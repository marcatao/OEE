package br.com.alumbra.oee.ui

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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import br.com.alumbra.oee.BuildConfig
import br.com.alumbra.oee.sessao.UsuarioApp
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/** Tela do usuário logado: menus conforme as permissões do portal e a situação da sincronização. */
@Composable
fun TelaPrincipal(
    usuario: UsuarioApp,
    ultimaSincronizacao: Long,
    horasOffline: Int,
    bloqueada: Boolean,
    sincronizando: Boolean,
    mensagem: String?,
    naRedeDaFabrica: Boolean,
    aoSincronizar: () -> Unit,
    aoSair: () -> Unit,
) {
    var confirmarSaida by remember { mutableStateOf(false) }

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        Box(
            Modifier
                .fillMaxWidth()
                .background(Brush.linearGradient(listOf(Cores.heroiInicio, Cores.heroiFim)))
                .padding(horizontal = 20.dp, vertical = 24.dp),
        ) {
            Column {
                Text("Olá, ${usuario.primeiroNome}", color = Color.White, fontSize = 24.sp, fontWeight = FontWeight.Bold)
                Text(usuario.perfis.joinToString(" · ").ifEmpty { "Sem perfil" }, color = Color.White.copy(alpha = .85f), fontSize = 14.sp)
                Text("OEE Alumbra ${BuildConfig.VERSION_NAME}", color = Color.White.copy(alpha = .7f), fontSize = 12.sp)
            }
        }

        Column(
            Modifier.padding(16.dp).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            CartaoSincronizacao(ultimaSincronizacao, horasOffline, bloqueada, sincronizando, mensagem, naRedeDaFabrica, aoSincronizar)

            if (usuario.operador || usuario.timeline) {
                CartaoMenu("Máquinas", "Timeline da máquina, ordem de produção, cavidades e motivo das paradas.", bloqueada)
            }
            if (usuario.mantenedor || usuario.aprovarOs || usuario.solicitarOs) {
                CartaoMenu("Ordens de serviço", "Abrir, aprovar e concluir SS/OS, apontamento com cronômetro e avaliação.", bloqueada)
            }
            if (!usuario.operador && !usuario.timeline && !usuario.mantenedor && !usuario.aprovarOs && !usuario.solicitarOs) {
                Text(
                    "Seu acesso não tem funções do aplicativo. Peça a um administrador do portal.",
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }

            Spacer(Modifier.height(8.dp))
            OutlinedButton(onClick = { confirmarSaida = true }, modifier = Modifier.fillMaxWidth().height(48.dp)) {
                Text("Sair (trocar de usuário)")
            }
        }
    }

    if (confirmarSaida) {
        AlertDialog(
            onDismissRequest = { confirmarSaida = false },
            title = { Text("Sair do aplicativo?") },
            text = { Text("Para entrar de novo será preciso estar na rede ${BuildConfig.REDE_WIFI}.") },
            confirmButton = { TextButton(onClick = { confirmarSaida = false; aoSair() }) { Text("Sair") } },
            dismissButton = { TextButton(onClick = { confirmarSaida = false }) { Text("Cancelar") } },
        )
    }
}

@Composable
private fun CartaoSincronizacao(
    ultima: Long,
    horasOffline: Int,
    bloqueada: Boolean,
    sincronizando: Boolean,
    mensagem: String?,
    naRedeDaFabrica: Boolean,
    aoSincronizar: () -> Unit,
) {
    val cores = if (bloqueada) {
        CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.errorContainer)
    } else {
        CardDefaults.cardColors()
    }
    Card(Modifier.fillMaxWidth(), colors = cores) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    if (bloqueada) "Sincronização necessária" else "Sincronizado",
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.SemiBold,
                    color = if (bloqueada) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary,
                )
            }
            val formato = SimpleDateFormat("dd/MM/yyyy HH:mm", Locale("pt", "BR"))
            Text(
                if (ultima > 0) "Última sincronização: ${formato.format(Date(ultima))}" else "Ainda não sincronizado.",
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            if (bloqueada) {
                Text(
                    "O aplicativo fica bloqueado depois de $horasOffline h sem sincronizar. Conecte à rede " +
                        "${BuildConfig.REDE_WIFI} e toque em Sincronizar.",
                    color = MaterialTheme.colorScheme.error,
                )
            }
            if (mensagem != null) Text(mensagem, color = MaterialTheme.colorScheme.error)
            Button(onClick = aoSincronizar, enabled = naRedeDaFabrica && !sincronizando, modifier = Modifier.fillMaxWidth()) {
                Text(if (sincronizando) "Sincronizando…" else "Sincronizar agora")
            }
            if (!naRedeDaFabrica) {
                Text("Fora da rede ${BuildConfig.REDE_WIFI}: o app trabalha com os dados do celular.", fontSize = 13.sp,
                    color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}

@Composable
private fun CartaoMenu(titulo: String, descricao: String, bloqueada: Boolean) {
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(titulo, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                Spacer(Modifier.size(8.dp))
                Text(
                    if (bloqueada) "bloqueado" else "em breve",
                    fontSize = 12.sp,
                    color = MaterialTheme.colorScheme.onPrimaryContainer,
                    modifier = Modifier.background(MaterialTheme.colorScheme.primaryContainer, MaterialTheme.shapes.small)
                        .padding(horizontal = 8.dp, vertical = 2.dp),
                )
            }
            Text(descricao, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}
