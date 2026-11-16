package br.com.alumbra.oee

import android.Manifest
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.browser.customtabs.CustomTabsIntent
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.lifecycle.lifecycleScope
import br.com.alumbra.oee.api.ApiPortal
import br.com.alumbra.oee.api.ErroApi
import br.com.alumbra.oee.rede.EstadoRede
import br.com.alumbra.oee.rede.VerificadorRede
import br.com.alumbra.oee.sessao.Sessao
import br.com.alumbra.oee.sessao.UsuarioApp
import br.com.alumbra.oee.ui.TelaInicial
import br.com.alumbra.oee.ui.TelaPrincipal
import br.com.alumbra.oee.ui.TemaOee
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

    private val pedirLocalizacao =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { tentativaRede++ }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        sessao = Sessao(this)
        usuario = if (sessao.token != null) sessao.usuario else null
        tratarRetornoDoLogin(intent)

        setContent {
            TemaOee {
                LaunchedEffect(tentativaRede) {
                    val estado = VerificadorRede.estadoAtual(this@MainActivity)
                    rede = estado
                    rede = estado.copy(portalAcessivel = VerificadorRede.portalResponde())
                }
                Box(Modifier.fillMaxSize().safeDrawingPadding()) {
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
                        TelaPrincipal(
                            usuario = u,
                            ultimaSincronizacao = sessao.ultimaSincronizacao,
                            horasOffline = sessao.horasOffline,
                            bloqueada = sessao.bloqueadaPorTempo(),
                            sincronizando = ocupado,
                            mensagem = mensagem,
                            naRedeDaFabrica = rede?.naRedeDaFabrica == true,
                            aoSincronizar = ::sincronizar,
                            aoSair = ::sair,
                        )
                    }
                }
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        tratarRetornoDoLogin(intent)
    }

    override fun onResume() {
        super.onResume()
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
            } catch (e: ErroApi) {
                mensagem = e.message
            } finally {
                ocupado = false
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
        sessao.sair()
        usuario = null
        mensagem = null
        // Avisa o portal se der; se estiver fora da rede, o token cai sozinho por falta de uso.
        if (token != null && rede?.naRedeDaFabrica == true) {
            lifecycleScope.launch { runCatching { ApiPortal.post("sair", token) } }
        }
    }
}
