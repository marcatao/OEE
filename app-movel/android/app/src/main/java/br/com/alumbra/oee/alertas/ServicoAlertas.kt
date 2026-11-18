package br.com.alumbra.oee.alertas

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.net.wifi.WifiManager
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import androidx.core.content.ContextCompat
import br.com.alumbra.oee.MainActivity
import br.com.alumbra.oee.R
import br.com.alumbra.oee.api.ErroApi
import br.com.alumbra.oee.maquinas.Alarme
import br.com.alumbra.oee.maquinas.RepositorioMaquinas
import br.com.alumbra.oee.os.RepositorioOs
import br.com.alumbra.oee.sessao.Sessao
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

/**
 * Alertas com o app FECHADO e a tela DESLIGADA (serviço em primeiro plano, com notificação fixa):
 * - mantenedor/aprovador: SS esperando alguém assumir — toca até o primeiro assumir; SS de máquina parada toca forte,
 *   acende a tela e abre o app por cima da tela de bloqueio;
 * - operador: parada sem motivo (98) da última máquina aberta no app.
 * Consulta o portal a cada [INTERVALO_MS]. Com o app na frente, quem toca é o próprio app (o serviço só consulta).
 */
class ServicoAlertas : Service() {

    private val escopo = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var laco: Job? = null
    private var toque: Job? = null
    private lateinit var sessao: Sessao
    private lateinit var alarme: Alarme
    private var travaCpu: PowerManager.WakeLock? = null
    private var travaWifi: WifiManager.WifiLock? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        sessao = Sessao(this)
        alarme = Alarme(this)
        criarCanais(this)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACAO_SILENCIAR) {
            silenciadoAte = System.currentTimeMillis() + 180_000
            pararToque()
            return START_STICKY
        }
        ServiceCompat.startForeground(
            this, ID_FIXA, notificacaoFixa("Vigiando alertas de máquina parada e de SS."),
            if (Build.VERSION.SDK_INT >= 34) ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE else 0,
        )
        if (sessao.token == null) {
            parar()
            return START_NOT_STICKY
        }
        segurarCpuEWifi()
        if (laco?.isActive != true) laco = escopo.launch { vigiar() }
        return START_STICKY
    }

    override fun onDestroy() {
        escopo.cancel()
        alarme.liberar()
        travaCpu?.takeIf { it.isHeld }?.release()
        travaWifi?.takeIf { it.isHeld }?.release()
        super.onDestroy()
    }

    private suspend fun vigiar() {
        val os = RepositorioOs { sessao.token }
        val maquinas = RepositorioMaquinas { sessao.token }
        while (escopo.isActive) {
            val usuario = sessao.usuario
            if (sessao.token == null || usuario == null) {
                parar()
                return
            }
            var urgente = false
            var aviso = false
            var titulo: String? = null
            var texto: String? = null
            try {
                if (usuario.mantenedor || usuario.aprovarOs) {
                    val pendentes = os.alerta() // sem as que ele recusou e sem as já assumidas
                    if (pendentes.isNotEmpty()) {
                        val parada = pendentes.filter { it.maquinaParada }
                        urgente = parada.isNotEmpty()
                        aviso = !urgente
                        titulo = if (urgente) "SS DE MÁQUINA PARADA" else "Nova solicitação de serviço"
                        texto = (parada.ifEmpty { pendentes }).joinToString(" · ") { listOfNotNull(it.maquina, it.titulo).joinToString(": ") } +
                            " — toque para assumir"
                    }
                }
                val vigiada = sessao.maquinaVigiada
                if (usuario.operador && vigiada > 0) {
                    val d = maquinas.detalhe(vigiada)
                    if (d.semMotivo.isNotEmpty()) {
                        urgente = true
                        titulo = "${d.nome}: PARADA SEM MOTIVO"
                        texto = "Informe o motivo da parada no aplicativo."
                    }
                }
            } catch (e: ErroApi) {
                if (e.semAcesso) {
                    parar()
                    return
                }
                // Sem rede: tenta de novo no próximo ciclo.
            }

            when {
                titulo == null -> {
                    pararToque()
                    cancelarAlerta()
                }
                System.currentTimeMillis() < silenciadoAte -> pararToque()
                appNaFrente -> pararToque() // o app na frente já mostra e toca
                else -> {
                    mostrarAlerta(titulo, texto.orEmpty(), urgente)
                    tocar(urgente || aviso, urgente)
                }
            }
            delay(INTERVALO_MS)
        }
    }

    /**
     * Toca em laço. A CADA toque das SS confere no portal se ainda há SS para este usuário: se outro mantenedor assumiu
     * (ou ele recusou), para na hora e tira o aviso.
     */
    private fun tocar(tocar: Boolean, urgente: Boolean) {
        if (!tocar) return pararToque()
        if (toque?.isActive == true) return
        val os = RepositorioOs { sessao.token }
        val usuario = sessao.usuario
        toque = escopo.launch {
            var forte = urgente
            while (isActive) {
                if (usuario != null && (usuario.mantenedor || usuario.aprovarOs) && sessao.maquinaVigiada == 0) {
                    val atuais = runCatching { os.alerta() }.getOrNull()
                    if (atuais != null && atuais.isEmpty()) {
                        cancelarAlerta()
                        break
                    }
                    if (atuais != null) forte = atuais.any { it.maquinaParada }
                }
                if (System.currentTimeMillis() < silenciadoAte || appNaFrente) break
                if (forte) alarme.urgente() else alarme.aviso()
                delay(if (forte) 3_000 else 6_000)
            }
            toque = null
        }
    }

    private fun pararToque() {
        toque?.cancel()
        toque = null
    }

    private fun mostrarAlerta(titulo: String, texto: String, urgente: Boolean) {
        val abrir = PendingIntent.getActivity(
            this, 1, Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val silenciar = PendingIntent.getService(
            this, 2, Intent(this, ServicoAlertas::class.java).setAction(ACAO_SILENCIAR),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val n = NotificationCompat.Builder(this, if (urgente) CANAL_URGENTE else CANAL_AVISO)
            .setSmallIcon(R.drawable.ic_maquina)
            .setContentTitle(titulo)
            .setContentText(texto)
            .setStyle(NotificationCompat.BigTextStyle().bigText(texto))
            .setPriority(if (urgente) NotificationCompat.PRIORITY_MAX else NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_ALARM)
            .setColor(ContextCompat.getColor(this, R.color.icone_fundo))
            .setContentIntent(abrir)
            .setFullScreenIntent(abrir, urgente) // acende a tela e abre por cima do bloqueio
            .addAction(0, "Silenciar 3 min", silenciar)
            .setOnlyAlertOnce(true)
            .setOngoing(urgente)
            .build()
        getSystemService(NotificationManager::class.java).notify(ID_ALERTA, n)
    }

    private fun cancelarAlerta() {
        getSystemService(NotificationManager::class.java).cancel(ID_ALERTA)
    }

    private fun notificacaoFixa(texto: String): Notification {
        val abrir = PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE)
        return NotificationCompat.Builder(this, CANAL_SERVICO)
            .setSmallIcon(R.drawable.ic_maquina)
            .setContentTitle("OEE Alumbra")
            .setContentText(texto)
            .setOngoing(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setContentIntent(abrir)
            .build()
    }

    /** CPU e Wi-Fi ligados com a tela apagada, para continuar consultando o portal. */
    private fun segurarCpuEWifi() {
        if (travaCpu == null) {
            travaCpu = getSystemService(PowerManager::class.java).newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "oee:alertas")
                .apply { setReferenceCounted(false); acquire() }
        }
        if (travaWifi == null) {
            @Suppress("DEPRECATION")
            val modo = if (Build.VERSION.SDK_INT >= 29) WifiManager.WIFI_MODE_FULL_LOW_LATENCY else WifiManager.WIFI_MODE_FULL_HIGH_PERF
            travaWifi = applicationContext.getSystemService(WifiManager::class.java).createWifiLock(modo, "oee:alertas")
                .apply { setReferenceCounted(false); acquire() }
        }
    }

    private fun parar() {
        pararToque()
        cancelarAlerta()
        ServiceCompat.stopForeground(this, ServiceCompat.STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    companion object {
        const val INTERVALO_MS = 20_000L
        private const val ID_FIXA = 10
        private const val ID_ALERTA = 11
        private const val CANAL_SERVICO = "servico"
        private const val CANAL_URGENTE = "maquina_parada"
        private const val CANAL_AVISO = "nova_ss"
        private const val ACAO_SILENCIAR = "br.com.alumbra.oee.SILENCIAR"

        /** O app está na frente? (ele mesmo toca; o serviço só consulta) */
        @Volatile var appNaFrente = false

        @Volatile private var silenciadoAte = 0L

        fun iniciar(contexto: Context) {
            ContextCompat.startForegroundService(contexto, Intent(contexto, ServicoAlertas::class.java))
        }

        fun parar(contexto: Context) {
            contexto.stopService(Intent(contexto, ServicoAlertas::class.java))
        }

        fun criarCanais(contexto: Context) {
            val nm = contexto.getSystemService(NotificationManager::class.java)
            val somAlarme = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM)
            val atributos = AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_ALARM)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build()
            nm.createNotificationChannel(NotificationChannel(CANAL_SERVICO, "Vigia de alertas", NotificationManager.IMPORTANCE_LOW).apply {
                description = "Notificação fixa enquanto o app vigia os alertas."
            })
            nm.createNotificationChannel(NotificationChannel(CANAL_URGENTE, "Máquina parada", NotificationManager.IMPORTANCE_HIGH).apply {
                description = "SS de máquina parada e parada sem motivo."
                setSound(somAlarme, atributos)
                enableVibration(true)
                vibrationPattern = longArrayOf(0, 500, 200, 500)
                lockscreenVisibility = Notification.VISIBILITY_PUBLIC
                setBypassDnd(true)
            })
            nm.createNotificationChannel(NotificationChannel(CANAL_AVISO, "Nova SS", NotificationManager.IMPORTANCE_HIGH).apply {
                description = "Solicitação de serviço esperando um mantenedor."
                enableVibration(true)
                lockscreenVisibility = Notification.VISIBILITY_PUBLIC
            })
        }
    }
}
