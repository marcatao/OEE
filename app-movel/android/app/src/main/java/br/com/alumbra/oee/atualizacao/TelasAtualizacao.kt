package br.com.alumbra.oee.atualizacao

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
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.Button
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import br.com.alumbra.oee.BuildConfig
import br.com.alumbra.oee.ui.CoresOee

/** Estado da atualização na tela: [progresso] null = parado; [erro] da última tentativa. */
data class EstadoAtualizacao(val info: InfoAtualizacao, val progresso: Float? = null, val erro: String? = null, val aguardandoPermissao: Boolean = false)

/** Cartão "Atualização disponível" da tela principal. */
@Composable
fun CartaoAtualizacao(estado: EstadoAtualizacao, aoAtualizar: () -> Unit) {
    val info = estado.info
    Surface(
        modifier = Modifier.fillMaxWidth(),
        shape = MaterialTheme.shapes.large,
        color = MaterialTheme.colorScheme.primaryContainer,
        border = androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.primary.copy(alpha = .35f)),
    ) {
        Column(Modifier.padding(18.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.Refresh, null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(22.dp))
                Spacer(Modifier.width(8.dp))
                Text("Atualização disponível", style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onPrimaryContainer)
            }
            Spacer(Modifier.height(4.dp))
            Text("Versão ${info.nome} (você está na ${BuildConfig.VERSION_NAME}) · ${mb(info.tamanho)}",
                style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onPrimaryContainer)
            info.observacao?.let {
                Spacer(Modifier.height(6.dp))
                Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onPrimaryContainer.copy(alpha = .8f))
            }
            CorpoAtualizacao(estado, aoAtualizar)
        }
    }
}

/** Atualização obrigatória: a tela inteira, até atualizar. */
@Composable
fun TelaAtualizacaoObrigatoria(estado: EstadoAtualizacao, aoAtualizar: () -> Unit, aoSair: () -> Unit) {
    val c = CoresOee.atual
    Box(
        Modifier.fillMaxSize().background(androidx.compose.ui.graphics.Brush.verticalGradient(listOf(c.heroiInicio, c.heroiFim))),
        contentAlignment = Alignment.Center,
    ) {
        Surface(Modifier.padding(24.dp).fillMaxWidth(), shape = MaterialTheme.shapes.extraLarge, color = MaterialTheme.colorScheme.surface) {
            Column(Modifier.padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Icon(Icons.Filled.Refresh, null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(48.dp))
                Text("Atualize o aplicativo", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
                Text(
                    "A versão ${estado.info.nome} é obrigatória. Você está na ${BuildConfig.VERSION_NAME}. O download é feito pelo portal " +
                        "(${mb(estado.info.tamanho)}) e o Android vai pedir para você tocar em Instalar.",
                    style = MaterialTheme.typography.bodyMedium, textAlign = TextAlign.Center,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                estado.info.observacao?.let {
                    Text(it, style = MaterialTheme.typography.bodySmall, textAlign = TextAlign.Center, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                Column(Modifier.fillMaxWidth()) { CorpoAtualizacao(estado, aoAtualizar) }
                androidx.compose.material3.TextButton(onClick = aoSair) { Text("Sair do aplicativo") }
            }
        }
    }
}

@Composable
private fun CorpoAtualizacao(estado: EstadoAtualizacao, aoAtualizar: () -> Unit) {
    Spacer(Modifier.height(12.dp))
    val progresso = estado.progresso
    if (progresso != null) {
        LinearProgressIndicator(
            progress = { progresso },
            modifier = Modifier.fillMaxWidth().height(8.dp).clip(MaterialTheme.shapes.small),
            trackColor = MaterialTheme.colorScheme.surfaceVariant,
        )
        Spacer(Modifier.height(4.dp))
        Text("Baixando pelo portal… ${(progresso * 100).toInt()}%", style = MaterialTheme.typography.bodySmall)
    } else {
        if (estado.aguardandoPermissao) {
            Text("Libere \"Permitir desta fonte\" para o OEE Alumbra e volte ao aplicativo.",
                style = MaterialTheme.typography.bodySmall, color = CoresOee.atual.alerta)
            Spacer(Modifier.height(8.dp))
        }
        estado.erro?.let {
            Text(it, style = MaterialTheme.typography.bodySmall, color = CoresOee.atual.erro)
            Spacer(Modifier.height(8.dp))
        }
        Button(onClick = aoAtualizar, modifier = Modifier.fillMaxWidth().height(50.dp), shape = MaterialTheme.shapes.medium) {
            Text(if (estado.erro != null) "Tentar de novo" else "Atualizar agora")
        }
    }
}

private fun mb(bytes: Long): String = if (bytes <= 0) "" else "%.1f MB".format(java.util.Locale("pt", "BR"), bytes / 1048576.0)
