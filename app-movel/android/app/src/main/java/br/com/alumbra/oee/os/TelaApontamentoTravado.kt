package br.com.alumbra.oee.os

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import br.com.alumbra.oee.ui.Aviso
import br.com.alumbra.oee.ui.BotaoPrincipal
import br.com.alumbra.oee.ui.Cartao
import br.com.alumbra.oee.ui.CoresOee
import br.com.alumbra.oee.ui.Situacao
import kotlinx.coroutines.delay
import java.time.Instant

/**
 * Tela TRAVADA do apontamento de horas: não sai com o botão voltar nem pelos menus; o cronômetro corre desde o início
 * (hora do portal). Só o botão deslizante "Encerrar apontamento" libera: pede a descrição do serviço, se conclui a OS e se
 * a máquina estava/está parada. Sem rede, o fim fica guardado no celular e é reenviado.
 */
@Composable
fun TelaApontamentoTravado(
    os: OsApp?,
    apontamento: ApontamentoOs,
    diferencaServidorMs: Long,
    pendente: EncerramentoPendente?,
    enviando: Boolean,
    erro: String?,
    aoEncerrar: (descricao: String, concluir: Boolean, maquinaParada: Boolean?, fimUtc: String) -> Unit,
    aoReenviar: () -> Unit,
    aoDesfazer: () -> Unit = {},
    agoraFixo: Long? = null,
) {
    BackHandler(enabled = true) {} // travada: o voltar não sai
    val vista = LocalView.current
    DisposableEffect(Unit) {
        vista.keepScreenOn = true
        onDispose { vista.keepScreenOn = false }
    }
    var agora by remember { mutableLongStateOf(agoraFixo ?: (System.currentTimeMillis() + diferencaServidorMs)) }
    LaunchedEffect(agoraFixo) {
        if (agoraFixo == null) {
            while (true) {
                agora = System.currentTimeMillis() + diferencaServidorMs
                delay(1000)
            }
        }
    }
    val inicio = paraMs(apontamento.inicioUtc) ?: agora
    val fimPendente = pendente?.let { paraMs(it.fimUtc) }
    val decorrido = ((fimPendente ?: agora) - inicio).coerceAtLeast(0) / 1000
    var encerrando by remember { mutableStateOf(false) }
    val c = CoresOee.atual

    Column(
        Modifier
            .fillMaxSize()
            .background(Brush.verticalGradient(listOf(c.heroiInicio, c.heroiFim)))
            .statusBarsPadding()
            .navigationBarsPadding()
            .imePadding()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        Text(
            if (pendente != null) "APONTAMENTO ENCERRADO — AGUARDANDO ENVIO" else "APONTAMENTO EM ANDAMENTO",
            style = MaterialTheme.typography.labelSmall, color = c.heroiSuave,
            modifier = Modifier.fillMaxWidth(), textAlign = TextAlign.Center,
        )
        Text(
            "%02d:%02d:%02d".format(decorrido / 3600, (decorrido % 3600) / 60, decorrido % 60),
            fontSize = 64.sp, fontWeight = FontWeight.Bold, fontFamily = FontFamily.Monospace, color = c.heroiTexto,
            modifier = Modifier.fillMaxWidth(), textAlign = TextAlign.Center,
        )
        Text("Início: ${apontamento.inicio ?: "—"}", color = c.heroiSuave, modifier = Modifier.fillMaxWidth(), textAlign = TextAlign.Center)

        Cartao {
            Text("OS ${os?.numero ?: apontamento.osId}", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(os?.titulo ?: "", style = MaterialTheme.typography.titleLarge)
            Spacer(Modifier.height(8.dp))
            Info("Setor", os?.setor)
            Info("Centro de custo", os?.centroCusto)
            Info("Solicitante", os?.solicitante?.let { "$it (vai avaliar o atendimento)" })
            Info("Máquina", os?.maquina)
        }

        when {
            pendente != null -> {
                Aviso(erro ?: "Enviando o encerramento ao portal…", if (erro != null) Situacao.ALERTA else Situacao.NEUTRA,
                    titulo = "Fim registrado às ${Instant.parse(pendente.fimUtc).let { java.text.SimpleDateFormat("HH:mm", java.util.Locale("pt", "BR")).format(java.util.Date(it.toEpochMilli())) }}")
                BotaoPrincipal("Enviar de novo", aoReenviar, carregando = enviando)
                if (erro != null && !enviando) {
                    // O portal recusou: volta ao cronômetro (o apontamento continua aberto no portal) para tentar de novo.
                    TextButton(onClick = aoDesfazer, modifier = Modifier.fillMaxWidth()) {
                        Text("Desfazer o encerramento e voltar ao cronômetro", color = c.heroiTexto)
                    }
                }
            }
            !encerrando -> {
                Spacer(Modifier.height(12.dp))
                BotaoDeslizante("Encerrar apontamento") { encerrando = true }
                Text("Arraste para a direita para encerrar", color = c.heroiSuave, style = MaterialTheme.typography.bodyMedium,
                    modifier = Modifier.fillMaxWidth(), textAlign = TextAlign.Center)
            }
            else -> PainelEncerrar(temMaquina = os?.maquina != null, maquinaParadaAtual = os?.maquinaParada ?: false, erro = erro,
                enviando = enviando, aoCancelar = { encerrando = false }) { desc, concluir, parada ->
                aoEncerrar(desc, concluir, parada, Instant.ofEpochMilli(agora).toString())
            }
        }
    }
}

@Composable
private fun PainelEncerrar(
    temMaquina: Boolean,
    maquinaParadaAtual: Boolean,
    erro: String?,
    enviando: Boolean,
    aoCancelar: () -> Unit,
    aoConfirmar: (String, Boolean, Boolean?) -> Unit,
) {
    var descricao by remember { mutableStateOf("") }
    var concluir by remember { mutableStateOf<String?>(null) }
    var parada by remember { mutableStateOf<String?>(if (temMaquina) null else "nao") }
    Cartao {
        Text("Encerrar apontamento", style = MaterialTheme.typography.titleLarge)
        Spacer(Modifier.height(10.dp))
        OutlinedTextField(descricao, { descricao = it }, label = { Text("O que foi feito (obrigatório)") },
            modifier = Modifier.fillMaxWidth(), minLines = 3)
        Spacer(Modifier.height(14.dp))
        Text("Quer concluir a OS?", style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(6.dp))
        EscolhaBotoes(listOf("sim" to "Sim, concluir", "nao" to "Não, continua aberta"), concluir) { concluir = it }
        if (temMaquina) {
            Spacer(Modifier.height(14.dp))
            Text("A máquina estava ou está parada?", style = MaterialTheme.typography.titleMedium)
            Text(if (maquinaParadaAtual) "Hoje a OS está marcada com máquina parada." else "Hoje a OS está marcada com máquina funcionando.",
                style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Spacer(Modifier.height(6.dp))
            EscolhaBotoes(listOf("sim" to "Sim, parada", "nao" to "Não"), parada) { parada = it }
        }
        if (erro != null) {
            Spacer(Modifier.height(10.dp))
            Aviso(erro, Situacao.ERRO)
        }
        Spacer(Modifier.height(16.dp))
        BotaoPrincipal(
            "Confirmar encerramento",
            { aoConfirmar(descricao.trim(), concluir == "sim", if (temMaquina) parada == "sim" else null) },
            habilitado = descricao.isNotBlank() && concluir != null && parada != null,
            carregando = enviando,
        )
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center) {
            TextButton(onClick = aoCancelar, enabled = !enviando) { Text("Voltar ao cronômetro") }
        }
        Spacer(Modifier.width(1.dp))
    }
}
