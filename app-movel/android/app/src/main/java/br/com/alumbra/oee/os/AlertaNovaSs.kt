package br.com.alumbra.oee.os

import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.unit.dp
import br.com.alumbra.oee.ui.CoresOee

/**
 * Aviso de SS nova no topo da tela (toca em todos os mantenedores que atendem a área até o primeiro assumir).
 * Vermelho quando a máquina está parada.
 */
@Composable
fun AlertaNovaSs(pendentes: List<SsApp>, assumindo: Int?, aoAssumir: (SsApp) -> Unit, aoVerDepois: () -> Unit) {
    val c = CoresOee.atual
    val parada = pendentes.any { it.maquinaParada }
    val transicao = rememberInfiniteTransition(label = "ss")
    val alfa by transicao.animateFloat(1f, .75f, infiniteRepeatable(tween(500), RepeatMode.Reverse), label = "alfa")
    val fundo = if (parada) c.erro else c.alerta
    Surface(
        Modifier.fillMaxWidth().statusBarsPadding().padding(10.dp),
        shape = MaterialTheme.shapes.large,
        color = fundo,
        shadowElevation = 12.dp,
    ) {
        Column(Modifier.padding(14.dp)) {
            Text(
                if (parada) "⚠ SS DE MÁQUINA PARADA" else "Nova solicitação de serviço",
                style = MaterialTheme.typography.titleLarge, color = c.heroiTexto, modifier = Modifier.alpha(alfa),
            )
            Text("${pendentes.size} aguardando um mantenedor assumir", style = MaterialTheme.typography.bodyMedium, color = c.heroiSuave)
            Spacer(Modifier.height(8.dp))
            Column(Modifier.heightIn(max = 300.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                pendentes.take(5).forEach { ss ->
                    Surface(shape = MaterialTheme.shapes.medium, color = MaterialTheme.colorScheme.surface) {
                        Row(Modifier.fillMaxWidth().padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(ss.titulo, style = MaterialTheme.typography.titleMedium)
                                Text(listOfNotNull(ss.maquina, ss.area, if (ss.maquinaParada) "MÁQUINA PARADA" else null).joinToString(" · "),
                                    style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                            Spacer(Modifier.width(8.dp))
                            Button(
                                onClick = { aoAssumir(ss) },
                                enabled = assumindo == null,
                                colors = ButtonDefaults.buttonColors(containerColor = fundo, contentColor = c.heroiTexto),
                                shape = MaterialTheme.shapes.medium,
                                modifier = Modifier.height(48.dp),
                            ) { Text(if (assumindo == ss.id) "…" else "Assumir") }
                        }
                    }
                }
            }
            TextButton(onClick = aoVerDepois, modifier = Modifier.align(Alignment.End)) {
                Text("Silenciar 3 min", color = c.heroiTexto)
            }
        }
    }
}
