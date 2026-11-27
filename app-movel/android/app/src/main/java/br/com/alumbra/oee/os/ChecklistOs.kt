package br.com.alumbra.oee.os

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import br.com.alumbra.oee.api.ErroApi
import br.com.alumbra.oee.ui.Aviso
import br.com.alumbra.oee.ui.BotaoPrincipal
import br.com.alumbra.oee.ui.Cartao
import br.com.alumbra.oee.ui.CoresOee
import br.com.alumbra.oee.ui.Selo
import br.com.alumbra.oee.ui.Situacao
import kotlinx.coroutines.launch

/** Rótulos curtos das respostas para caber em três botões lado a lado. */
private val ROTULOS_CURTOS = mapOf("ok" to "OK", "nok" to "Não OK", "na" to "N/A")

/**
 * Checklist da OS preventiva: cada item com OK / Não OK / Não se aplica e uma observação. As escolhas ficam no
 * celular até tocar em "Salvar checklist" (só os itens alterados vão ao portal). Concluir a OS exige todos respondidos.
 */
@Composable
fun CartaoChecklist(
    itens: List<ItemChecklist>,
    opcoes: List<Opcao>,
    podeEditar: Boolean,
    salvando: Boolean,
    aoSalvar: (List<ItemChecklist>) -> Unit,
) {
    if (itens.isEmpty()) return
    // Edição local por item (recomeça quando a OS volta do portal com outras respostas).
    val editados = remember(itens) { mutableStateMapOf<Int, ItemChecklist>() }
    val atual = { item: ItemChecklist -> editados[item.id] ?: item }
    val respondidos = itens.count { atual(it).resposta != null }
    val alterados = itens.mapNotNull { editados[it.id] }.filter { e ->
        val original = itens.first { it.id == e.id }
        e.resposta != original.resposta || e.observacao.orEmpty().trim() != original.observacao.orEmpty().trim()
    }
    val escolhas = (opcoes.ifEmpty { listOf(Opcao("ok", "OK"), Opcao("nok", "Não OK"), Opcao("na", "Não se aplica")) })
        .map { it.valor to (ROTULOS_CURTOS[it.valor] ?: it.rotulo) }

    Cartao {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Checklist da preventiva", style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
            Selo("$respondidos de ${itens.size}", if (respondidos == itens.size) Situacao.OK else Situacao.ALERTA)
        }
        if (respondidos < itens.size) {
            Text("Responda todos os itens para poder concluir a OS.", style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        itens.forEachIndexed { i, original ->
            val item = atual(original)
            HorizontalDivider(Modifier.padding(vertical = 12.dp), color = CoresOee.atual.borda)
            Text("${i + 1}. ${item.descricao}", style = MaterialTheme.typography.bodyLarge)
            Spacer(Modifier.height(8.dp))
            if (podeEditar) {
                EscolhaBotoes(escolhas, item.resposta) { valor ->
                    editados[item.id] = item.copy(resposta = if (item.resposta == valor) null else valor)
                }
                Spacer(Modifier.height(6.dp))
                OutlinedTextField(
                    item.observacao.orEmpty(), { editados[item.id] = item.copy(observacao = it.take(300)) },
                    label = { Text(if (item.resposta == "nok") "O que está errado?" else "Observação (opcional)") },
                    modifier = Modifier.fillMaxWidth(), singleLine = false, maxLines = 3,
                )
            } else {
                Text(
                    escolhas.firstOrNull { it.first == item.resposta }?.second ?: "Sem resposta",
                    style = MaterialTheme.typography.labelLarge,
                )
                if (!item.observacao.isNullOrBlank()) Text(item.observacao, style = MaterialTheme.typography.bodyMedium)
            }
            if (original.respondidoPor != null && editados[item.id] == null) {
                Text("Respondido por ${original.respondidoPor}${original.respondidoEm?.let { " em $it" } ?: ""}",
                    style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
        if (podeEditar) {
            Spacer(Modifier.height(14.dp))
            BotaoPrincipal(
                if (alterados.isEmpty()) "Checklist salvo" else "Salvar checklist (${alterados.size})",
                { aoSalvar(alterados) },
                habilitado = alterados.isNotEmpty(),
                carregando = salvando,
            )
        }
    }
}

/**
 * Checklist da OS que carrega e grava sozinho (usado na tela travada do cronômetro, que não tem o detalhe da OS).
 * OS sem checklist: não mostra nada.
 */
@Composable
fun ChecklistDaOs(repositorio: RepositorioOs, osId: Int) {
    var os by remember(osId) { mutableStateOf<OsApp?>(null) }
    var erro by remember { mutableStateOf<String?>(null) }
    var salvando by remember { mutableStateOf(false) }
    val escopo = rememberCoroutineScope()
    LaunchedEffect(osId) {
        try {
            os = repositorio.detalhe(osId)
        } catch (e: ErroApi) {
            erro = e.message
        }
    }
    val d = os ?: return
    if (d.checklist.isEmpty()) return
    Column {
        if (erro != null) Aviso(erro!!, Situacao.ERRO, Modifier.padding(bottom = 8.dp))
        CartaoChecklist(d.checklist, d.respostasChecklist, d.podeResponderChecklist, salvando) { alterados ->
            escopo.launch {
                salvando = true
                try {
                    os = repositorio.responderChecklist(osId, alterados)
                    erro = null
                } catch (e: ErroApi) {
                    erro = e.message
                } finally {
                    salvando = false
                }
            }
        }
    }
}
