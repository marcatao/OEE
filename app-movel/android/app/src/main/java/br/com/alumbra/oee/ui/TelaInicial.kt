package br.com.alumbra.oee.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import br.com.alumbra.oee.BuildConfig
import br.com.alumbra.oee.R
import br.com.alumbra.oee.rede.EstadoRede

/** Tela de entrada: situação da rede e do portal e o botão para entrar pelo portal. */
@Composable
fun TelaInicial(
    rede: EstadoRede?,
    mensagem: String?,
    entrando: Boolean,
    aoEntrar: () -> Unit,
    aoVerificarRede: () -> Unit,
    aoPedirPermissao: () -> Unit,
) {
    val c = CoresOee.atual
    Column(
        Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            .verticalScroll(rememberScrollState()),
    ) {
        FundoHeroi {
            Column(
                Modifier
                    .fillMaxWidth()
                    .statusBarsPadding()
                    .padding(start = 24.dp, end = 24.dp, top = 40.dp, bottom = 72.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                LogoAlumbra(76.dp)
                Spacer(Modifier.height(18.dp))
                Text("OEE Alumbra", style = MaterialTheme.typography.headlineMedium, color = c.heroiTexto)
                Spacer(Modifier.height(4.dp))
                Text(
                    "Produção e manutenção no chão de fábrica",
                    style = MaterialTheme.typography.bodyLarge,
                    color = c.heroiSuave,
                    textAlign = TextAlign.Center,
                )
            }
        }

        Column(
            Modifier
                .offset(y = (-44).dp)
                .padding(horizontal = 16.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            Cartao {
                Text("Entrar", style = MaterialTheme.typography.titleLarge)
                Spacer(Modifier.height(4.dp))
                Text(
                    "Use a sua conta Microsoft da Alumbra. O primeiro acesso deve ser feito antes no portal, pelo computador.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Spacer(Modifier.height(18.dp))

                LinhaRede(rede)
                HorizontalDivider(Modifier.padding(vertical = 12.dp), color = c.borda)
                LinhaPortal(rede)

                if (rede?.semPermissaoLocalizacao == true) {
                    Spacer(Modifier.height(14.dp))
                    Aviso(
                        titulo = "Permita a localização",
                        texto = "O Android só mostra o nome da rede Wi-Fi para apps com essa permissão.",
                        situacao = Situacao.ALERTA,
                    )
                    TextButton(onClick = aoPedirPermissao, modifier = Modifier.align(Alignment.End)) {
                        Text("Permitir")
                    }
                }
                if (mensagem != null) {
                    Spacer(Modifier.height(14.dp))
                    Aviso(texto = mensagem, situacao = Situacao.ERRO, titulo = "Não foi possível entrar")
                }

                Spacer(Modifier.height(20.dp))
                BotaoPrincipal(
                    texto = if (entrando) "Entrando…" else "Entrar pelo portal",
                    aoTocar = aoEntrar,
                    habilitado = rede?.naRedeDaFabrica == true,
                    carregando = entrando,
                    icone = R.drawable.ic_portal,
                )
                if (rede != null && !rede.naRedeDaFabrica) {
                    Spacer(Modifier.height(8.dp))
                    Text(
                        "Disponível só na rede ${BuildConfig.REDE_WIFI}.",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.fillMaxWidth(),
                        textAlign = TextAlign.Center,
                    )
                }
            }

            TextButton(onClick = aoVerificarRede, modifier = Modifier.align(Alignment.CenterHorizontally)) {
                Icon(Icons.Filled.Refresh, null, Modifier.size(18.dp))
                Spacer(Modifier.size(6.dp))
                Text("Verificar a rede de novo")
            }

            Text(
                "Versão ${BuildConfig.VERSION_NAME} · build ${BuildConfig.VERSION_CODE}",
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.fillMaxWidth().navigationBarsPadding(),
                textAlign = TextAlign.Center,
            )
        }
    }
}

@Composable
private fun LinhaRede(rede: EstadoRede?) {
    val wifi = BuildConfig.REDE_WIFI
    when {
        rede == null -> LinhaEstado(R.drawable.ic_wifi, "Rede Wi-Fi", "Verificando…", Situacao.NEUTRA, "", carregando = true)
        !rede.noWifi -> LinhaEstado(R.drawable.ic_wifi_off, "Sem Wi-Fi", "Conecte à rede $wifi.", Situacao.ERRO, "Desconectado")
        rede.semPermissaoLocalizacao -> LinhaEstado(R.drawable.ic_wifi, "Rede Wi-Fi", "Não foi possível ler o nome da rede.", Situacao.ALERTA, "Permissão")
        !rede.naRedeDaFabrica -> LinhaEstado(
            R.drawable.ic_wifi, "Outra rede",
            "Conectado a ${rede.nomeRede ?: "rede desconhecida (ligue a localização)"}. Use a $wifi.",
            Situacao.ERRO, "Fora da fábrica",
        )
        else -> LinhaEstado(R.drawable.ic_wifi, "Rede da fábrica", wifi, Situacao.OK, "Conectado")
    }
}

@Composable
private fun LinhaPortal(rede: EstadoRede?) {
    when (rede?.portalAcessivel) {
        null -> LinhaEstado(R.drawable.ic_portal, "Portal OEE", "Verificando…", Situacao.NEUTRA, "", carregando = true)
        true -> LinhaEstado(R.drawable.ic_portal, "Portal OEE", "Respondendo normalmente.", Situacao.OK, "Online")
        false -> LinhaEstado(R.drawable.ic_portal, "Portal OEE", rede.motivoPortal ?: "Não respondeu. Confira a rede.", Situacao.ERRO, "Sem resposta")
    }
}
