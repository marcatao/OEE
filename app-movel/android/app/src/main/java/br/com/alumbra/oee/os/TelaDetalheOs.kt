package br.com.alumbra.oee.os

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import br.com.alumbra.oee.api.ErroApi
import br.com.alumbra.oee.ui.Aviso
import br.com.alumbra.oee.ui.BarraTopo
import br.com.alumbra.oee.ui.BotaoPrincipal
import br.com.alumbra.oee.ui.Cartao
import br.com.alumbra.oee.ui.CoresOee
import br.com.alumbra.oee.ui.Selo
import br.com.alumbra.oee.ui.Situacao
import br.com.alumbra.oee.ui.TituloSecao
import kotlinx.coroutines.launch

/** Detalhe da OS: dados, apontamentos, iniciar apontamento (cronômetro), aguardar material/retomar/concluir e avaliar. */
@Composable
fun TelaDetalheOs(
    repositorio: RepositorioOs,
    osId: Int,
    temApontamentoRodando: Boolean,
    aoVoltar: () -> Unit,
    aoIniciou: () -> Unit,
    aoAvaliar: (Int) -> Unit,
    aoPerderAcesso: (String) -> Unit,
) {
    var os by remember { mutableStateOf<OsApp?>(null) }
    var erro by remember { mutableStateOf<String?>(null) }
    var ocupado by remember { mutableStateOf(false) }
    var acaoConfirmar by remember { mutableStateOf<Pair<String, String>?>(null) }
    val escopo = rememberCoroutineScope()

    fun executar(bloco: suspend () -> OsApp, depois: (OsApp) -> Unit = {}) {
        escopo.launch {
            ocupado = true
            try {
                val r = bloco()
                os = r
                erro = null
                depois(r)
            } catch (e: ErroApi) {
                if (e.semAcesso) aoPerderAcesso(e.message ?: "") else erro = e.message
            } finally {
                ocupado = false
            }
        }
    }
    LaunchedEffect(osId) { executar({ repositorio.detalhe(osId) }) }

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        BarraTopo(os?.let { "OS ${it.numero}" } ?: "Ordem de serviço", os?.statusRotulo, aoVoltar)
        val d = os
        if (d == null) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                if (erro != null) Aviso(erro!!, Situacao.ERRO, Modifier.padding(16.dp)) else CircularProgressIndicator()
            }
            return
        }
        Column(
            Modifier.verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding(),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            if (erro != null) Aviso(erro!!, Situacao.ERRO)
            Cartao {
                Text(d.titulo, style = MaterialTheme.typography.titleLarge)
                Spacer(Modifier.height(6.dp))
                SelosOs(d.prioridade, d.maquinaParada)
                Spacer(Modifier.height(10.dp))
                Text(d.descricao.orEmpty(), style = MaterialTheme.typography.bodyLarge)
                HorizontalDivider(Modifier.padding(vertical = 12.dp), color = CoresOee.atual.borda)
                Info("Máquina", d.maquina)
                Info("Área executante", d.area)
                Info("Setor", d.setor)
                Info("Centro de custo", d.centroCusto)
                Info("Solicitante", d.solicitante)
                Info("Aberta em", d.abertura)
                Info("Tipo", d.tipo)
                Info("Responsável", d.responsavel)
                if (d.totalMinutos > 0) Info("Horas apontadas", duracao(d.totalMinutos.toLong()))
                if (!d.solucao.isNullOrBlank()) Info("Solução", d.solucao)
            }

            if (d.podeExecutar && d.status in listOf("aberta", "em_execucao", "aguardando_material")) {
                if (d.meuApontamento == null) {
                    BotaoPrincipal(
                        texto = if (temApontamentoRodando) "Você já tem um apontamento rodando" else "Iniciar apontamento",
                        aoTocar = { executar({ repositorio.iniciar(d.id) }) { aoIniciou() } },
                        habilitado = !temApontamentoRodando,
                        carregando = ocupado,
                    )
                }
                d.acoes.forEach { (acao, rotulo) ->
                    OutlinedButton(onClick = { acaoConfirmar = acao to rotulo }, enabled = !ocupado,
                        modifier = Modifier.fillMaxWidth().height(50.dp), shape = MaterialTheme.shapes.medium) { Text(rotulo) }
                }
            }
            if (d.podeAvaliar) {
                BotaoPrincipal("Avaliação do solicitante", { aoAvaliar(d.id) })
            }

            TituloSecao("Apontamentos de horas")
            if (d.apontamentos.isEmpty()) Text("Nenhum apontamento ainda.", color = MaterialTheme.colorScheme.onSurfaceVariant)
            d.apontamentos.forEach { a ->
                Cartao {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(a.mantenedor.orEmpty(), style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                        if (a.fim == null) Selo("Rodando", Situacao.OK) else Text(duracao(a.minutos.toLong()), style = MaterialTheme.typography.labelLarge)
                    }
                    Text("${a.inicio} – ${a.fim ?: "agora"}", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    if (!a.descricao.isNullOrBlank()) Text(a.descricao, style = MaterialTheme.typography.bodyMedium)
                }
            }
        }
    }

    acaoConfirmar?.let { (acao, rotulo) ->
        var obs by remember(acao) { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { acaoConfirmar = null },
            title = { Text(rotulo) },
            text = {
                Column {
                    if (acao == "concluir") Text("Ao concluir, o solicitante avalia o serviço.")
                    Spacer(Modifier.width(8.dp))
                    OutlinedTextField(obs, { obs = it }, label = { Text(if (acao == "concluir") "Solução (o que foi feito)" else "Observação") },
                        modifier = Modifier.fillMaxWidth().padding(top = 8.dp), minLines = 2)
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    acaoConfirmar = null
                    executar({ repositorio.acao(osId, acao, obs.ifBlank { null }) })
                }) { Text("Confirmar") }
            },
            dismissButton = { TextButton(onClick = { acaoConfirmar = null }) { Text("Cancelar") } },
        )
    }
}

@Composable
fun Info(rotulo: String, valor: String?) {
    if (valor.isNullOrBlank()) return
    Row(Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
        Text(rotulo, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.width(130.dp))
        Text(valor, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
    }
}
