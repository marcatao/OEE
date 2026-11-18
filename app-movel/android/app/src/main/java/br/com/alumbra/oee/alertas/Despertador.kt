package br.com.alumbra.oee.alertas

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.SystemClock
import br.com.alumbra.oee.sessao.Sessao

/**
 * "Despertador" do serviço de alertas: a cada ~15 min (mesmo em modo de economia do Android) confere se o serviço
 * está rodando e o religa se o sistema o tiver matado. Precisa do app fora da economia de bateria.
 */
class Despertador : BroadcastReceiver() {
    override fun onReceive(contexto: Context, intent: Intent) {
        if (Sessao(contexto).token != null) {
            runCatching { ServicoAlertas.iniciar(contexto) }
            agendar(contexto)
        }
    }

    companion object {
        private const val INTERVALO_MS = 15 * 60 * 1000L

        private fun intencao(contexto: Context) = PendingIntent.getBroadcast(
            contexto, 30, Intent(contexto, Despertador::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        fun agendar(contexto: Context) {
            contexto.getSystemService(AlarmManager::class.java).setAndAllowWhileIdle(
                AlarmManager.ELAPSED_REALTIME_WAKEUP, SystemClock.elapsedRealtime() + INTERVALO_MS, intencao(contexto),
            )
        }

        fun cancelar(contexto: Context) {
            contexto.getSystemService(AlarmManager::class.java).cancel(intencao(contexto))
        }
    }
}
