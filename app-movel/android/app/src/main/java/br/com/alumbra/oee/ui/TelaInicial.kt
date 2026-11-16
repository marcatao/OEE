package br.com.alumbra.oee.ui

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import br.com.alumbra.oee.BuildConfig
import br.com.alumbra.oee.R
import br.com.alumbra.oee.rede.EstadoRede

@Composable
fun TelaInicial(
    rede: EstadoRede?,
    aoVerificarRede: () -> Unit,
    aoPedirPermissao: () -> Unit,
) {
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        // Faixa do topo, como o "herói" do portal.
        Box(
            Modifier
                .fillMaxWidth()
                .background(Brush.linearGradient(listOf(Cores.heroiInicio, Cores.heroiFim)))
                .padding(horizontal = 24.dp, vertical = 40.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(
                    Modifier.size(56.dp).clip(RoundedCornerShape(14.dp)).background(Color.White.copy(alpha = .15f)),
                    contentAlignment = Alignment.Center,
                ) {
                    Image(painterResource(R.drawable.logo_a), contentDescription = null, Modifier.size(56.dp))
                }
                Spacer(Modifier.size(16.dp))
                Column {
                    Text("OEE Alumbra", color = Color.White, fontSize = 26.sp, fontWeight = FontWeight.Bold)
                    Text(
                        "Versão ${BuildConfig.VERSION_NAME} (build ${BuildConfig.VERSION_CODE})",
                        color = Color.White.copy(alpha = .8f),
                        fontSize = 14.sp,
                    )
                }
            }
        }

        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
            CartaoRede(rede, aoVerificarRede, aoPedirPermissao)

            Card(Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("Entrar", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                    Text(
                        "O login pelo portal chega na próxima versão. Esta versão serve para testar a instalação " +
                            "e a rede Wi-Fi da fábrica.",
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Button(onClick = {}, enabled = false, modifier = Modifier.fillMaxWidth().height(48.dp)) {
                        Text("Entrar com a conta Alumbra")
                    }
                }
            }
        }
    }
}

@Composable
private fun CartaoRede(rede: EstadoRede?, aoVerificarRede: () -> Unit, aoPedirPermissao: () -> Unit) {
    val (cor, titulo, detalhe) = when {
        rede == null -> Triple(MaterialTheme.colorScheme.outline, "Verificando a rede…", "")
        !rede.noWifi -> Triple(MaterialTheme.colorScheme.error, "Sem Wi-Fi", "Conecte à rede ${BuildConfig.REDE_WIFI}.")
        rede.semPermissaoLocalizacao -> Triple(
            MaterialTheme.colorScheme.error,
            "Permissão necessária",
            "O Android só mostra o nome da rede Wi-Fi com a permissão de localização.",
        )
        !rede.naRedeDaFabrica -> Triple(
            MaterialTheme.colorScheme.error,
            "Fora da rede da fábrica",
            "Rede atual: ${rede.nomeRede ?: "desconhecida (ligue a localização)"}. Conecte à ${BuildConfig.REDE_WIFI}.",
        )
        else -> Triple(MaterialTheme.colorScheme.primary, "Na rede ${BuildConfig.REDE_WIFI}", "")
    }
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(12.dp).clip(CircleShape).background(cor))
                Spacer(Modifier.size(10.dp))
                Text(titulo, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
            }
            if (detalhe.isNotEmpty()) Text(detalhe, color = MaterialTheme.colorScheme.onSurfaceVariant)
            if (rede?.portalAcessivel != null) {
                Text(
                    if (rede.portalAcessivel) "Portal OEE respondendo." else "Portal OEE não respondeu.",
                    color = if (rede.portalAcessivel) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.error,
                )
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(onClick = aoVerificarRede) { Text("Verificar de novo") }
                if (rede?.semPermissaoLocalizacao == true) {
                    Button(onClick = aoPedirPermissao) { Text("Permitir") }
                }
            }
        }
    }
}
