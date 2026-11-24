package br.com.alumbra.oee

import android.Manifest
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.browser.customtabs.CustomTabsIntent
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import br.com.alumbra.oee.alertas.ServicoAlertas
import br.com.alumbra.oee.api.ApiPortal
import br.com.alumbra.oee.api.ErroApi
import br.com.alumbra.oee.maquinas.Alarme
import br.com.alumbra.oee.maquinas.RepositorioMaquinas
import br.com.alumbra.oee.maquinas.TelaMaquina
import br.com.alumbra.oee.maquinas.TelaMaquinas
import br.com.alumbra.oee.os.AlertaNovaSs
import br.com.alumbra.oee.os.EncerramentoPendente
import br.com.alumbra.oee.os.RepositorioOs
import br.com.alumbra.oee.os.ResumoOs
import br.com.alumbra.oee.os.TelaAbrirSs
import br.com.alumbra.oee.os.TelaApontamentoTravado
import br.com.alumbra.oee.os.TelaAvaliacao
import br.com.alumbra.oee.os.TelaDetalheOs
import br.com.alumbra.oee.os.TelaOrdensServico
import br.com.alumbra.oee.rede.EstadoRede
import br.com.alumbra.oee.rede.VerificadorRede
import br.com.alumbra.oee.sessao.Sessao
import br.com.alumbra.oee.sessao.UsuarioApp
import br.com.alumbra.oee.ui.TelaInicial
import br.com.alumbra.oee.ui.TelaPrincipal
import br.com.alumbra.oee.ui.TemaOee
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import org.json.JSONObject

class MainActivity : ComponentActivity() {

    private lateinit var sessao: Sessao

    private var tentativaRede by mutableIntStateOf(0)
    private var rede by mutableStateOf<EstadoRede?>(null)
    private var usuario by mutableStateOf<UsuarioApp?>(null)
    private var mensagem by mutableStateOf<String?>(null)
    private var ocupado by mutableStateOf(false)
    private var relogio by mutableIntStateOf(0) // muda para reavaliar o bloqueio de 48 h
    private var tela by mutableStateOf<Tela>(Tela.Principal)
    private val repositorioMaquinas by lazy { RepositorioMaquinas(java.io.File(filesDir, "maquinas").apply { mkdirs() }) { sessao.token } }
    private val repositorioOs by lazy { RepositorioOs { sessao.token } }
    private val alarme by lazy { Alarme(this) }

    // Ordens de serviço: resumo consultado de tempos em tempos (SS nova, apontamento rodando).
    private var resumoOs by mutableStateOf<ResumoOs?>(null)
    private var diferencaServidor by mutableLongStateOf(0L)
    private var silenciadoAte by mutableLongStateOf(0L)
    private var assumindo by mutableStateOf<Int?>(null)
    private var itensAlerta by mutableStateOf<List<br.com.alumbra.oee.alertas.ItemAlerta>>(emptyList())
    /** SS que ainda tocam para este usuário (pendentes, que ele pode assumir e não recusou). */
    private var alertaSs by mutableStateOf<List<br.com.alumbra.oee.os.SsApp>>(emptyList())
    private var atualizarResumo by mutableIntStateOf(0)
    private var encerramento by mutableStateOf<EncerramentoPendente?>(null)
    private var enviandoEncerramento by mutableStateOf(false)
    private var erroEncerramento by mutableStateOf<String?>(null)
    // Atualização do app pelo portal (Segurança › Aplicativo).
    private var atualizacao by mutableStateOf<br.com.alumbra.oee.atualizacao.EstadoAtualizacao?>(null)
    private var ultimaVerificacaoVersao = 0L

    /** Telas internas (o usuário logado navega entre elas; "voltar" sobe um nível). */
    private sealed interface Tela {
        data object Principal : Tela
        data object Maquinas : Tela
        data class Maquina(val id: Int, val nome: String) : Tela
        data class DetalhesOp(val opId: Int, val numero: String, val maquinaId: Int, val maquinaNome: String) : Tela
        data class Apontamento(val id: Int, val maquinaId: Int, val maquinaNome: String) : Tela
        data object Os : Tela
        data class OsDetalhe(val id: Int) : Tela
        data object AbrirSs : Tela
        data class Avaliacao(val id: Int) : Tela
    }

    private fun Tela.acima(): Tela = when (this) {
        is Tela.Maquina -> Tela.Maquinas
        is Tela.DetalhesOp -> Tela.Maquina(maquinaId, maquinaNome)
        is Tela.Apontamento -> Tela.Maquina(maquinaId, maquinaNome)
        is Tela.OsDetalhe, Tela.AbrirSs, is Tela.Avaliacao -> Tela.Os
        else -> Tela.Principal
    }

    private val pedirNotificacoes = registerForActivityResult(ActivityResultContracts.RequestPermission()) {}

    private val pedirLocalizacao =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { tentativaRede++ }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Topo verde em todas as telas: ícones da barra de status sempre claros.
        enableEdgeToEdge(statusBarStyle = SystemBarStyle.dark(android.graphics.Color.TRANSPARENT))
        sessao = Sessao(this)
        usuario = if (sessao.token != null) sessao.usuario else null
        encerramento = EncerramentoPendente.de(sessao.encerramentoPendente)
        sessao.maquinaVigiada = 0 // máquinas não alertam mais com o app fechado (versões antigas gravavam aqui)
        tratarRetornoDoLogin(intent)
        if (usuario != null) ligarAlertas()

        setContent {
            TemaOee {
                LaunchedEffect(tentativaRede) {
                    val estado = VerificadorRede.estadoAtual(this@MainActivity)
                    rede = estado
                    val (responde, motivo) = VerificadorRede.portalResponde()
                    rede = estado.copy(portalAcessivel = responde, motivoPortal = motivo)
                }
                Box(Modifier.fillMaxSize()) {
                    val u = usuario
                    if (u == null) {
                        TelaInicial(
                            rede = rede,
                            mensagem = mensagem,
                            entrando = ocupado,
                            aoEntrar = ::abrirLoginNoPortal,
                            aoVerificarRede = { rede = null; tentativaRede++ },
                            aoPedirPermissao = {
                                pedirLocalizacao.launch(
                                    arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION),
                                )
                            },
                        )
                    } else {
                        relogio // lido aqui para recompor quando o app volta
                        val bloqueada = sessao.bloqueadaPorTempo()
                        val usaOs = u.mantenedor
                        LaunchedEffect(u, bloqueada, atualizarResumo) { if (usaOs && !bloqueada) acompanharOs() }
                        val resumo = resumoOs
                        val pendentes = alertaSs
                        LaunchedEffect(pendentes.isNotEmpty(), silenciadoAte) { tocarSsNova(pendentes.isNotEmpty()) }

                        val rodando = resumo?.rodando
                        val pend = encerramento
                        if (rodando != null || pend != null) {
                            // Apontamento de horas rodando: o app fica travado no cronômetro.
                            TelaApontamentoTravado(
                                os = resumo?.rodandoOs,
                                apontamento = rodando ?: br.com.alumbra.oee.os.ApontamentoOs(pend!!.apontamentoId, pend.osId, null, null, null, null, 0, null),
                                diferencaServidorMs = diferencaServidor,
                                pendente = pend,
                                enviando = enviandoEncerramento,
                                erro = erroEncerramento,
                                aoEncerrar = { desc, concluir, parada, fim ->
                                    rodando?.let { encerrar(EncerramentoPendente(it.id, it.osId, desc, concluir, parada, fim)) }
                                },
                                aoReenviar = { pend?.let(::encerrar) },
                                aoDesfazer = {
                                    sessao.encerramentoPendente = null
                                    encerramento = null
                                    erroEncerramento = null
                                    atualizarResumo++
                                },
                            )
                        } else if (atualizacao?.info?.obrigatoria == true) {
                            // Versão obrigatória no portal: só volta a usar o app depois de atualizar.
                            br.com.alumbra.oee.atualizacao.TelaAtualizacaoObrigatoria(atualizacao!!, ::atualizarApp, ::sair)
                        } else {
                            val atual = if (bloqueada) Tela.Principal else tela
                            BackHandler(enabled = atual != Tela.Principal) { tela = atual.acima() }
                            TelaLogado(u, atual, bloqueada, resumo)
                        }
                        // Confere a versão no portal ao entrar e a cada 30 minutos com o app aberto.
                        LaunchedEffect(u) {
                            while (true) {
                                verificarAtualizacao()
                                kotlinx.coroutines.delay(30 * 60_000L)
                            }
                        }
                        if (pendentes.isNotEmpty() && System.currentTimeMillis() >= silenciadoAte && !bloqueada) {
                            AlertaNovaSs(pendentes, assumindo, aoAssumir = ::assumir, aoRecusar = ::recusar, aoVerDepois = { silenciadoAte = System.currentTimeMillis() + 180_000 })
                        }
                    }
                }
            }
        }
    }

    @androidx.compose.runtime.Composable
    private fun TelaLogado(u: UsuarioApp, atual: Tela, bloqueada: Boolean, resumo: ResumoOs?) {
        when (atual) {
            Tela.Principal -> TelaPrincipal(
                usuario = u,
                ultimaSincronizacao = sessao.ultimaSincronizacao,
                horasOffline = sessao.horasOffline,
                bloqueada = bloqueada,
                sincronizando = ocupado,
                mensagem = mensagem,
                naRedeDaFabrica = rede?.naRedeDaFabrica == true,
                aoSincronizar = ::sincronizar,
                aoSair = ::sair,
                aoAbrirFuncao = { funcao -> tela = if (funcao == "maquinas") Tela.Maquinas else Tela.Os },
                pendenciasOs = (resumo?.pendentes?.size ?: 0) + (resumo?.avaliacoes ?: 0),
                itensAlerta = if (u.mantenedor) itensAlerta else null,
                cartaoAtualizacao = atualizacao?.takeIf { it.info.temNova }?.let { e ->
                    { br.com.alumbra.oee.atualizacao.CartaoAtualizacao(e, ::atualizarApp) }
                },
                aoAjustarAlerta = { runCatching { startActivity(it) } },
                aoTestarAlerta = {
                    ServicoAlertas.testar(this)
                    Toast.makeText(this, "Apague a tela agora: o alarme toca em 15 segundos.", Toast.LENGTH_LONG).show()
                },
            )
            Tela.Maquinas -> TelaMaquinas(
                repositorio = repositorioMaquinas,
                aoVoltar = { tela = Tela.Principal },
                aoAbrir = { tela = Tela.Maquina(it.id, it.nome) },
                aoPerderAcesso = ::perderAcesso,
            )
            is Tela.Maquina -> TelaMaquina(
                repositorio = repositorioMaquinas,
                maquinaId = atual.id,
                nomeInicial = atual.nome,
                podeAlterar = u.operador,
                aoVoltar = { tela = Tela.Maquinas },
                aoPerderAcesso = ::perderAcesso,
                aoDetalhesOp = { op -> tela = Tela.DetalhesOp(op.id, op.numero, atual.id, atual.nome) },
                aoAbrirApontamento = { a -> tela = Tela.Apontamento(a.id, atual.id, atual.nome) },
            )
            is Tela.Apontamento -> br.com.alumbra.oee.maquinas.TelaApontamento(
                repositorio = repositorioMaquinas,
                apontamentoId = atual.id,
                podeAlterar = u.operador,
                aoVoltar = { tela = Tela.Maquina(atual.maquinaId, atual.maquinaNome) },
                aoPerderAcesso = ::perderAcesso,
            )
            is Tela.DetalhesOp -> br.com.alumbra.oee.maquinas.TelaDetalhesOp(
                repositorio = repositorioMaquinas,
                opId = atual.opId,
                numero = atual.numero,
                aoVoltar = { tela = Tela.Maquina(atual.maquinaId, atual.maquinaNome) },
                aoPerderAcesso = ::perderAcesso,
            )
            Tela.Os -> TelaOrdensServico(
                repositorio = repositorioOs,
                resumo = resumo,
                aoVoltar = { tela = Tela.Principal },
                aoAbrirOs = { tela = Tela.OsDetalhe(it) },
                aoAbrirSs = { tela = Tela.AbrirSs },
                aoAvaliar = { tela = Tela.Avaliacao(it) },
                aoAtualizarResumo = { atualizarResumo++ },
                aoPerderAcesso = ::perderAcesso,
            )
            is Tela.OsDetalhe -> TelaDetalheOs(
                repositorio = repositorioOs,
                osId = atual.id,
                temApontamentoRodando = resumo?.rodando != null,
                aoVoltar = { tela = Tela.Os },
                aoIniciou = { atualizarResumo++ },
                aoAvaliar = { tela = Tela.Avaliacao(it) },
                aoPerderAcesso = ::perderAcesso,
            )
            Tela.AbrirSs -> TelaAbrirSs(
                repositorio = repositorioOs,
                aoVoltar = { tela = Tela.Os },
                aoEnviada = { msg ->
                    Toast.makeText(this, msg, Toast.LENGTH_LONG).show()
                    tela = Tela.Os
                    atualizarResumo++
                },
                aoPerderAcesso = ::perderAcesso,
            )
            is Tela.Avaliacao -> TelaAvaliacao(
                repositorio = repositorioOs,
                osId = atual.id,
                aoVoltar = { tela = Tela.Os },
                aoConcluir = { msg ->
                    Toast.makeText(this, msg, Toast.LENGTH_LONG).show()
                    tela = Tela.Os
                    atualizarResumo++
                },
                aoPerderAcesso = ::perderAcesso,
            )
        }
    }

    /**
     * Liga o serviço de alertas (toca com o app fechado e a tela desligada) e pede, uma vez, o que ele precisa:
     * notificações, ficar fora da economia de bateria e abrir em tela cheia sobre o bloqueio.
     */
    private fun ligarAlertas() {
        val u = sessao.usuario ?: return
        if (!u.mantenedor) return // só as SS dos mantenedores tocam com o app fechado
        val ajustes = getSharedPreferences("ajustes", MODE_PRIVATE)
        if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != android.content.pm.PackageManager.PERMISSION_GRANTED) {
            pedirNotificacoes.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
        runCatching { ServicoAlertas.iniciar(this) }
        val energia = getSystemService(android.os.PowerManager::class.java)
        if (!energia.isIgnoringBatteryOptimizations(packageName) && !ajustes.getBoolean("pediu_bateria", false)) {
            ajustes.edit().putBoolean("pediu_bateria", true).apply()
            runCatching {
                startActivity(Intent(android.provider.Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:$packageName")))
            }
        } else if (Build.VERSION.SDK_INT >= 34 && !getSystemService(android.app.NotificationManager::class.java).canUseFullScreenIntent() &&
            !ajustes.getBoolean("pediu_tela_cheia", false)
        ) {
            ajustes.edit().putBoolean("pediu_tela_cheia", true).apply()
            runCatching {
                startActivity(Intent(android.provider.Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT, Uri.parse("package:$packageName")))
            }
        }
    }

    /** Consulta o resumo das OS a cada 15 s (com o app na frente). */
    private suspend fun acompanharOs() {
        lifecycle.repeatOnLifecycle(Lifecycle.State.RESUMED) {
            while (true) {
                try {
                    val antes = System.currentTimeMillis()
                    val r = repositorioOs.resumo()
                    r.servidorAgoraMs?.let { diferencaServidor = it - (antes + System.currentTimeMillis()) / 2 }
                    resumoOs = r
                    alertaSs = r.pendentes.filterNot { it.recusada }
                    // O portal já encerrou (ex.: pelo computador): descarta o encerramento guardado.
                    if (r.rodando == null && encerramento != null) reenviarOuDescartar()
                } catch (e: ErroApi) {
                    if (e.semAcesso) perderAcesso(e.message ?: "")
                }
                delay(15_000)
            }
        }
    }

    /**
     * SS esperando alguém assumir: toca até o primeiro mantenedor assumir (mais forte se a máquina está parada). A CADA
     * toque confere no portal se ainda há SS para este usuário: se outro assumiu (ou ele recusou), para na hora.
     */
    private suspend fun tocarSsNova(tem: Boolean) {
        if (!tem) return
        lifecycle.repeatOnLifecycle(Lifecycle.State.RESUMED) {
            while (true) {
                val atuais = try {
                    repositorioOs.alerta()
                } catch (e: ErroApi) {
                    if (e.semAcesso) perderAcesso(e.message ?: "")
                    null // sem rede: segue com a última lista
                }
                if (atuais != null) alertaSs = atuais
                if (alertaSs.isEmpty()) {
                    atualizarResumo++
                    break
                }
                val parada = alertaSs.any { it.maquinaParada }
                if (System.currentTimeMillis() >= silenciadoAte) {
                    if (parada) alarme.urgente() else alarme.aviso()
                }
                delay(if (parada) 3_000 else 6_000)
            }
        }
    }

    /** Recusa a SS: só ela para de tocar para este mantenedor; o histórico registra "SS não aceita por …". */
    private fun recusar(ss: br.com.alumbra.oee.os.SsApp) {
        assumindo = ss.id
        lifecycleScope.launch {
            try {
                alertaSs = repositorioOs.recusar(ss.id)
            } catch (e: ErroApi) {
                if (e.semAcesso) perderAcesso(e.message ?: "") else Toast.makeText(this@MainActivity, e.message, Toast.LENGTH_LONG).show()
            } finally {
                assumindo = null
                atualizarResumo++
            }
        }
    }

    private fun assumir(ss: br.com.alumbra.oee.os.SsApp) {
        assumindo = ss.id
        lifecycleScope.launch {
            try {
                val os = repositorioOs.assumir(ss.id)
                tela = Tela.OsDetalhe(os.id)
            } catch (e: ErroApi) {
                if (e.semAcesso) perderAcesso(e.message ?: "") else Toast.makeText(this@MainActivity, e.message, Toast.LENGTH_LONG).show()
            } finally {
                assumindo = null
                atualizarResumo++
            }
        }
    }

    /** Guarda o encerramento no celular e envia; sem rede, fica guardado até conseguir. */
    private fun encerrar(p: EncerramentoPendente) {
        encerramento = p
        sessao.encerramentoPendente = p.json()
        enviandoEncerramento = true
        erroEncerramento = null
        lifecycleScope.launch {
            try {
                val os = repositorioOs.encerrar(p)
                sessao.encerramentoPendente = null
                encerramento = null
                resumoOs = resumoOs?.copy(rodando = null, rodandoOs = null)
                tela = Tela.OsDetalhe(os.id)
                Toast.makeText(this@MainActivity, if (p.concluir) "OS concluída: o solicitante vai avaliar." else "Apontamento encerrado.", Toast.LENGTH_LONG).show()
            } catch (e: ErroApi) {
                if (e.semAcesso) perderAcesso(e.message ?: "") else erroEncerramento = e.message
            } finally {
                enviandoEncerramento = false
                atualizarResumo++
            }
        }
    }

    private fun reenviarOuDescartar() {
        if (!enviandoEncerramento) encerramento?.let(::encerrar)
    }

    /** O portal recusou o token (saiu, expirou ou perdeu o acesso): volta para a entrada. */
    private fun perderAcesso(motivo: String) {
        ServicoAlertas.parar(this)
        java.io.File(filesDir, "maquinas").deleteRecursively() // o próximo usuário não vê dados guardados deste
        sessao.sair()
        usuario = null
        tela = Tela.Principal
        mensagem = motivo.ifBlank { "Entre de novo no aplicativo." }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        tratarRetornoDoLogin(intent)
    }

    override fun onPause() {
        super.onPause()
        ServicoAlertas.appNaFrente = false
    }

    override fun onResume() {
        super.onResume()
        // Voltou da tela "Instalar apps desconhecidos": continua a atualização sozinho se a permissão foi dada.
        atualizacao?.takeIf { it.aguardandoPermissao }?.let {
            if (br.com.alumbra.oee.atualizacao.Atualizador.podeInstalar(this)) atualizarApp() else atualizacao = it.copy(aguardandoPermissao = false)
        }
        verificarAtualizacao()
        ServicoAlertas.appNaFrente = true
        itensAlerta = br.com.alumbra.oee.alertas.ConferenciaAlertas.itens(this)
        // Religa o serviço de alertas a cada abertura (o sistema pode tê-lo matado).
        val u = sessao.usuario
        if (sessao.token != null && u != null && u.mantenedor) runCatching { ServicoAlertas.iniciar(this) }
        tentativaRede++
        relogio++
    }

    /** Abre /app/entrar no navegador do celular; o portal faz o login pelo Entra ID e volta com um código. */
    private fun abrirLoginNoPortal() {
        mensagem = null
        val desafio = Sessao.desafioDe(sessao.novoVerificador())
        val endereco = Uri.parse(BuildConfig.PORTAL_URL.trimEnd('/') + "/app/entrar?desafio=$desafio")
        CustomTabsIntent.Builder().setShowTitle(true).build().launchUrl(this, endereco)
    }

    /** br.com.alumbra.oee://login?codigo=... (ou ?erro=...) */
    private fun tratarRetornoDoLogin(intent: Intent?) {
        val dados = intent?.data ?: return
        if (dados.scheme != "br.com.alumbra.oee" || dados.host != "login") return
        setIntent(Intent(this, MainActivity::class.java)) // não reprocessa ao girar a tela

        dados.getQueryParameter("erro")?.let { mensagem = it; return }
        val codigo = dados.getQueryParameter("codigo") ?: return
        val verificador = sessao.verificadorPendente() ?: run {
            mensagem = "O login expirou. Toque em Entrar de novo."
            return
        }
        ocupado = true
        lifecycleScope.launch {
            try {
                val corpo = JSONObject()
                    .put("codigo", codigo)
                    .put("verificador", verificador)
                    .put("dispositivo", "${Build.MANUFACTURER} ${Build.MODEL}")
                    .put("versao", BuildConfig.VERSION_NAME)
                val resposta = ApiPortal.post("token", null, corpo)
                sessao.limparVerificador()
                sessao.gravarLogin(resposta.getString("token"), resposta)
                usuario = sessao.usuario
                mensagem = null
                ligarAlertas()
            } catch (e: ErroApi) {
                mensagem = e.message
            } finally {
                ocupado = false
            }
        }
    }

    /** Pergunta ao portal se há versão nova (no máximo a cada 10 min, ou [forcar]); sem rede, não faz nada. */
    private fun verificarAtualizacao(forcar: Boolean = false) {
        val token = sessao.token ?: return
        val agora = System.currentTimeMillis()
        if (!forcar && agora - ultimaVerificacaoVersao < 10 * 60_000L) return
        if (atualizacao?.progresso != null) return
        ultimaVerificacaoVersao = agora
        lifecycleScope.launch {
            runCatching { br.com.alumbra.oee.atualizacao.Atualizador.verificar(token) }.onSuccess { info ->
                atualizacao = info?.takeIf { it.temNova }?.let { novo ->
                    atualizacao?.takeIf { it.info.codigo == novo.codigo }?.copy(info = novo) ?: br.com.alumbra.oee.atualizacao.EstadoAtualizacao(novo)
                }
            }
        }
    }

    /** Baixa o APK novo pelo portal e abre o instalador do Android (o usuário toca em "Instalar"). */
    private fun atualizarApp() {
        val token = sessao.token ?: return
        val estado = atualizacao ?: return
        val atualizador = br.com.alumbra.oee.atualizacao.Atualizador
        if (!atualizador.podeInstalar(this)) {
            atualizacao = estado.copy(aguardandoPermissao = true, erro = null)
            runCatching { atualizador.abrirPermissao(this) }
            return
        }
        atualizacao = estado.copy(progresso = 0f, erro = null, aguardandoPermissao = false)
        lifecycleScope.launch {
            try {
                val apk = atualizador.baixar(this@MainActivity, token, estado.info) { p -> atualizacao = atualizacao?.copy(progresso = p) }
                atualizacao = atualizacao?.copy(progresso = null)
                atualizador.instalar(this@MainActivity, apk)
            } catch (e: ErroApi) {
                if (e.semAcesso) perderAcesso(e.message ?: "") else atualizacao = atualizacao?.copy(progresso = null, erro = e.message)
            } catch (e: Exception) {
                atualizacao = atualizacao?.copy(progresso = null, erro = "Não foi possível abrir o instalador: ${e.message}")
            }
        }
    }

    private fun sincronizar() {
        val token = sessao.token ?: return
        ocupado = true
        mensagem = null
        lifecycleScope.launch {
            try {
                sessao.gravarSincronizacao(ApiPortal.get("eu", token))
                usuario = sessao.usuario
                relogio++
                verificarAtualizacao(forcar = true)
            } catch (e: ErroApi) {
                if (e.semAcesso) {
                    sessao.sair()
                    usuario = null
                }
                mensagem = e.message
            } finally {
                ocupado = false
            }
        }
    }

    private fun sair() {
        val token = sessao.token
        ServicoAlertas.parar(this)
        java.io.File(filesDir, "maquinas").deleteRecursively() // o próximo usuário não vê dados guardados deste
        sessao.sair()
        usuario = null
        tela = Tela.Principal
        mensagem = null
        // Avisa o portal se der; se estiver fora da rede, o token cai sozinho por falta de uso.
        if (token != null && rede?.naRedeDaFabrica == true) {
            lifecycleScope.launch { runCatching { ApiPortal.post("sair", token) } }
        }
    }
}
