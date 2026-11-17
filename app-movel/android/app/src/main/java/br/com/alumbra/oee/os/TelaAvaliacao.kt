package br.com.alumbra.oee.os

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
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
import br.com.alumbra.oee.ui.Situacao
import kotlinx.coroutines.launch

/**
 * Avaliação da OS concluída pelo SOLICITANTE no celular do mantenedor: resumo da solicitação e do que foi feito,
 * nota (Excelente / Boa / Ruim — Ruim pede o motivo e devolve a OS à manutenção) e assinatura com o dedo.
 */
@Composable
fun TelaAvaliacao(repositorio: RepositorioOs, osId: Int, aoVoltar: () -> Unit, aoConcluir: (String) -> Unit, aoPerderAcesso: (String) -> Unit) {
    var os by remember { mutableStateOf<OsApp?>(null) }
    var erro by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(osId) {
        try {
            os = repositorio.detalhe(osId)
        } catch (e: ErroApi) {
            if (e.semAcesso) aoPerderAcesso(e.message ?: "") else erro = e.message
        }
    }
    ConteudoAvaliacao(os, erro, aoVoltar) { avaliacao, obs, png, nome, aoFim ->
        try {
            aoConcluir(repositorio.avaliar(osId, avaliacao, obs, png, nome))
        } catch (e: ErroApi) {
            if (e.semAcesso) aoPerderAcesso(e.message ?: "") else aoFim(e.message)
        }
    }
}

@Composable
fun ConteudoAvaliacao(
    os: OsApp?,
    erroCarga: String?,
    aoVoltar: () -> Unit,
    aoEnviar: suspend (avaliacao: String, obs: String?, png: String, nome: String, aoErro: (String?) -> Unit) -> Unit,
) {
    val assinatura = remember { Assinatura() }
    var nota by remember { mutableStateOf<String?>(null) }
    var obs by remember { mutableStateOf("") }
    var nome by remember(os?.solicitante) { mutableStateOf(os?.solicitante.orEmpty()) }
    var erro by remember { mutableStateOf<String?>(null) }
    var enviando by remember { mutableStateOf(false) }
    val escopo = rememberCoroutineScope()

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        BarraTopo("Avaliação do atendimento", os?.let { "OS ${it.numero}" }, aoVoltar)
        if (os == null) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                if (erroCarga != null) Aviso(erroCarga, Situacao.ERRO, Modifier.padding(16.dp)) else CircularProgressIndicator()
            }
            return
        }
        Column(
            Modifier.verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding().padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Aviso("Entregue o celular ao solicitante (${os.solicitante ?: "—"}) para ele avaliar e assinar.", Situacao.NEUTRA)
            Cartao {
                Text("O QUE FOI PEDIDO", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Text(os.titulo, style = MaterialTheme.typography.titleLarge)
                Text(os.descricao.orEmpty(), style = MaterialTheme.typography.bodyLarge)
                Spacer(Modifier.height(6.dp))
                Info("Máquina", os.maquina)
                Info("Aberta em", os.abertura)
                HorizontalDivider(Modifier.padding(vertical = 12.dp), color = CoresOee.atual.borda)
                Text("O QUE FOI FEITO", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                if (!os.solucao.isNullOrBlank()) Text(os.solucao, style = MaterialTheme.typography.bodyLarge)
                os.apontamentos.forEach { a ->
                    Spacer(Modifier.height(6.dp))
                    Text("${a.mantenedor} · ${a.inicio} – ${a.fim ?: "agora"} · ${duracao(a.minutos.toLong())}",
                        style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    if (!a.descricao.isNullOrBlank()) Text(a.descricao, style = MaterialTheme.typography.bodyMedium)
                }
                Spacer(Modifier.height(6.dp))
                Info("Concluída em", os.concluidaEm)
                Info("Total de horas", duracao(os.totalMinutos.toLong()))
            }
            Cartao {
                Text("Como foi o atendimento?", style = MaterialTheme.typography.titleLarge)
                Spacer(Modifier.height(10.dp))
                EscolhaBotoes(listOf("excelente" to "Excelente", "boa" to "Boa", "ruim" to "Ruim"), nota) { nota = it }
                if (nota == "ruim") {
                    Spacer(Modifier.height(10.dp))
                    Aviso("Na avaliação Ruim a OS volta para a manutenção. Conte o motivo.", Situacao.ALERTA)
                }
                Spacer(Modifier.height(10.dp))
                OutlinedTextField(obs, { obs = it.take(500) }, label = { Text(if (nota == "ruim") "Motivo (obrigatório)" else "Comentário (opcional)") },
                    modifier = Modifier.fillMaxWidth(), minLines = 2)
            }
            Cartao {
                Text("Assinatura do solicitante", style = MaterialTheme.typography.titleLarge)
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(nome, { nome = it.take(120) }, label = { Text("Nome de quem assina") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                Spacer(Modifier.height(10.dp))
                QuadroAssinatura(assinatura)
            }
            if (erro != null) Aviso(erro!!, Situacao.ERRO)
            BotaoPrincipal(
                "Confirmar avaliação",
                {
                    escopo.launch {
                        enviando = true
                        erro = null
                        aoEnviar(nota!!, obs.trim().ifBlank { null }, assinatura.paraPngBase64(), nome.trim()) { erro = it }
                        enviando = false
                    }
                },
                habilitado = nota != null && !assinatura.vazia && nome.isNotBlank() && (nota != "ruim" || obs.isNotBlank()),
                carregando = enviando,
            )
        }
    }
}
