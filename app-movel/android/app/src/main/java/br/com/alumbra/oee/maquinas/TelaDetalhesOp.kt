package br.com.alumbra.oee.maquinas

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyListScope
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Tab
import androidx.compose.material3.ScrollableTabRow
import androidx.compose.material3.TabRowDefaults
import androidx.compose.material3.TabRowDefaults.tabIndicatorOffset
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import br.com.alumbra.oee.api.ErroApi
import br.com.alumbra.oee.ui.Aviso
import br.com.alumbra.oee.ui.BarraTopo
import br.com.alumbra.oee.ui.Cartao
import br.com.alumbra.oee.ui.CoresOee
import br.com.alumbra.oee.ui.Selo
import br.com.alumbra.oee.ui.Situacao
import br.com.alumbra.oee.ui.TituloSecao
import java.text.NumberFormat
import java.util.Locale

/**
 * Detalhes da OP (aberto pelo botão no cartão da OP, na tela da máquina): as abas da OP no portal — OP e custo,
 * Kardex, Empenhos e Engenharia (estrutura e roteiro padrão). Só leitura; sem rede, mostra o último dado guardado.
 */
@Composable
fun TelaDetalhesOp(
    repositorio: RepositorioMaquinas,
    opId: Int,
    numero: String,
    aoVoltar: () -> Unit,
    aoPerderAcesso: (String) -> Unit,
) {
    var detalhes by remember { mutableStateOf<DetalhesOp?>(null) }
    var erro by remember { mutableStateOf<String?>(null) }
    var recarregar by remember { mutableIntStateOf(0) }
    var aba by rememberSaveable { mutableIntStateOf(0) }

    LaunchedEffect(opId, recarregar) {
        erro = null
        try {
            detalhes = repositorio.detalhesOp(opId)
        } catch (e: ErroApi) {
            if (e.semAcesso) {
                aoPerderAcesso(e.message ?: "")
            } else {
                erro = e.message
                if (detalhes == null) detalhes = repositorio.detalhesOpGuardado(opId)
            }
        }
    }
    ConteudoDetalhesOp(numero, detalhes, erro, aba, { aba = it }, aoVoltar, { recarregar++ })
}

@Composable
fun ConteudoDetalhesOp(
    numero: String,
    detalhes: DetalhesOp?,
    erro: String?,
    aba: Int,
    aoAba: (Int) -> Unit,
    aoVoltar: () -> Unit,
    aoAtualizar: () -> Unit,
) {
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        BarraTopo(
            titulo = "OP ${detalhes?.op?.numero ?: numero}",
            subtitulo = detalhes?.op?.let { listOfNotNull(it.produto, it.descricao).joinToString(" · ") },
            aoVoltar = aoVoltar,
        ) {
            IconButton(onClick = aoAtualizar) { Icon(Icons.Filled.Refresh, "Atualizar", tint = CoresOee.atual.heroiTexto) }
        }
        if (detalhes == null) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                if (erro != null) Aviso(erro, Situacao.ERRO, Modifier.padding(16.dp)) else CircularProgressIndicator()
            }
            return
        }
        val abas = listOf("OP", "Kardex", "Empenhos", "Engenharia")
        val contagens = listOf(null, detalhes.kardexTotal, detalhes.empenhos.size, detalhes.estrutura.size + detalhes.operacoes.size)
        // Rolável: cada aba do tamanho do texto ("Engenharia" inteiro), mesmo em celular estreito.
        ScrollableTabRow(
            selectedTabIndex = aba,
            edgePadding = 0.dp,
            containerColor = MaterialTheme.colorScheme.surface,
            indicator = { pos ->
                TabRowDefaults.SecondaryIndicator(Modifier.tabIndicatorOffset(pos[aba]), height = 3.dp, color = MaterialTheme.colorScheme.primary)
            },
        ) {
            abas.forEachIndexed { i, rotulo ->
                Tab(
                    selected = aba == i,
                    onClick = { aoAba(i) },
                    selectedContentColor = MaterialTheme.colorScheme.primary,
                    unselectedContentColor = MaterialTheme.colorScheme.onSurfaceVariant,
                    text = {
                        Column(horizontalAlignment = Alignment.CenterHorizontally) {
                            Text(rotulo, maxLines = 1, softWrap = false, style = MaterialTheme.typography.labelLarge, fontWeight = if (aba == i) FontWeight.Bold else FontWeight.Normal)
                            contagens[i]?.let { Text("$it", style = MaterialTheme.typography.labelSmall) }
                        }
                    },
                )
            }
        }
        LazyColumn(
            Modifier.fillMaxSize().navigationBarsPadding(),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            if (erro != null) item { Aviso("$erro Mostrando os últimos dados recebidos.", Situacao.ALERTA) }
            when (aba) {
                0 -> abaOp(detalhes)
                1 -> abaKardex(detalhes)
                2 -> abaEmpenhos(detalhes)
                else -> abaEngenharia(detalhes)
            }
        }
    }
}

// ---------- Aba OP ----------

private fun LazyListScope.abaOp(d: DetalhesOp) {
    val op = d.op
    item {
        Cartao {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("OP ${op.numero}", style = MaterialTheme.typography.titleLarge)
                    Text(listOfNotNull(op.produto, op.descricao).joinToString(" · "), style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                Selo(op.statusRotulo, if (op.encerradaEm != null) Situacao.NEUTRA else Situacao.OK)
            }
            if (op.quantidade > 0) {
                Spacer(Modifier.height(14.dp))
                LinearProgressIndicator(
                    progress = { (op.produzido / op.quantidade).toFloat().coerceIn(0f, 1f) },
                    modifier = Modifier.fillMaxWidth().height(8.dp).clip(MaterialTheme.shapes.small),
                    trackColor = MaterialTheme.colorScheme.surfaceVariant,
                )
                Spacer(Modifier.height(6.dp))
                Text("Produzido ${qtd(op.produzido)} de ${qtd(op.quantidade)} ${op.um ?: ""} · ${pct(op.produzido / op.quantidade)}",
                    style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Spacer(Modifier.height(12.dp))
            Row {
                Numero("Quantidade real (IoT)", "${qtd(op.quantidadeReal)} pç", Modifier.weight(1f), destaque = true)
                Numero("Produzido no Protheus", qtd(op.produzidoProtheus), Modifier.weight(1f))
            }
        }
    }
    item {
        Cartao {
            Text("Dados da OP", style = MaterialTheme.typography.titleMedium)
            Spacer(Modifier.height(6.dp))
            Campo("Emissão", op.emissao)
            Campo("Previsão", "${op.previsaoInicio ?: "-"} a ${op.previsaoFim ?: "-"}")
            Campo("Encerrada em", op.encerradaEm)
            Campo("Perda", qtd(op.perda))
            Campo("Roteiro", op.roteiro)
            Campo("Revisão da estrutura", op.revisao)
            Campo("Armazém", op.armazem)
            Campo("Centro de custo", op.centroCusto)
            Campo("Tipo", op.tipo)
            op.observacao?.let { Campo("Observação", it) }
        }
    }
    val c = d.custo
    item {
        Cartao {
            Text("Custo por peça", style = MaterialTheme.typography.titleMedium)
            Spacer(Modifier.height(10.dp))
            Row {
                Numero("Simulado (OEE)", moeda(c.simulado, 4), Modifier.weight(1f), destaque = true)
                Numero("Meta", moeda(c.meta, 4), Modifier.weight(1f))
            }
            Spacer(Modifier.height(10.dp))
            Row {
                Numero("Protheus", moeda(c.protheus, 4), Modifier.weight(1f))
                Numero("Divergência", c.divergencia?.let { (if (it > 0) "+" else "") + pct(it) } ?: "—", Modifier.weight(1f),
                    alerta = (c.divergencia ?: 0.0) > 0)
            }
            HorizontalDivider(Modifier.padding(vertical = 12.dp), color = CoresOee.atual.borda)
            Campo("Baixas (RE)", moeda(c.baixas))
            Campo("Notas com OP", moeda(c.notas))
            Campo("Devoluções (DE)", moeda(c.devolucoes))
        }
    }
    if (c.componentes.isNotEmpty()) {
        item { TituloSecao("Consumo por componente") }
        items(c.componentes) { comp ->
            Cartao(preenchimento = PaddingValues(14.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(comp.produto, style = MaterialTheme.typography.titleSmall)
                        comp.descricao?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                        Text("${qtd(comp.quantidade)} ${comp.um ?: ""}", style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    Column(horizontalAlignment = Alignment.End) {
                        Text(moeda(comp.valor), style = MaterialTheme.typography.titleSmall)
                        Text("${moeda(comp.porPeca, 4)}/pç", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        }
    }
}

// ---------- Aba Kardex ----------

private fun LazyListScope.abaKardex(d: DetalhesOp) {
    if (d.kardex.isEmpty()) {
        item { Aviso("Nenhum movimento do Protheus (produção, baixa, devolução ou nota) nesta OP.", Situacao.NEUTRA) }
        return
    }
    item {
        Text(
            if (d.kardexTotal > d.kardex.size) "Os ${d.kardex.size} movimentos mais recentes de ${d.kardexTotal}." else "${d.kardexTotal} movimentos, do mais recente para o mais antigo.",
            style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
    items(d.kardex) { m ->
        Cartao(preenchimento = PaddingValues(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Selo(m.rotulo, when (m.tipo) { "producao" -> Situacao.OK; "devolucao" -> Situacao.ALERTA; else -> Situacao.NEUTRA })
                Spacer(Modifier.width(8.dp))
                Text(m.data ?: "", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.weight(1f))
                Text(
                    (if (m.entrada) "+" else "−") + qtd(m.quantidade) + " " + (m.um ?: ""),
                    style = MaterialTheme.typography.titleMedium,
                    color = if (m.entrada) CoresOee.atual.ok else MaterialTheme.colorScheme.onSurface,
                )
            }
            Spacer(Modifier.height(6.dp))
            Text(listOfNotNull(m.produto, m.produtoDescricao).joinToString(" · "), style = MaterialTheme.typography.bodyMedium)
            Text(listOf(m.descricao, m.documento.takeIf { it.isNotBlank() }?.let { "doc. $it" }).filterNotNull().joinToString(" · "),
                style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Spacer(Modifier.height(6.dp))
            Row {
                Text("Valor ${moeda(m.valor)}", style = MaterialTheme.typography.labelMedium, modifier = Modifier.weight(1f))
                Text("Produzido acum. ${qtd(m.produzidoAcumulado)} · ${moeda(m.custoPecaAcumulado, 4)}/pç",
                    style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}

// ---------- Aba Empenhos ----------

private fun LazyListScope.abaEmpenhos(d: DetalhesOp) {
    if (d.empenhos.isEmpty()) {
        item { Aviso("Nenhum empenho (material reservado) para esta OP.", Situacao.NEUTRA) }
        return
    }
    items(d.empenhos) { e ->
        Cartao(preenchimento = PaddingValues(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(e.componente, style = MaterialTheme.typography.titleSmall)
                    e.descricao?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                }
                Selo(if (e.saldo <= 0.0000001) "Atendido" else pct(e.atendido), if (e.saldo <= 0.0000001) Situacao.OK else Situacao.ALERTA)
            }
            Spacer(Modifier.height(10.dp))
            LinearProgressIndicator(
                progress = { (e.atendido ?: 0.0).toFloat().coerceIn(0f, 1f) },
                modifier = Modifier.fillMaxWidth().height(6.dp).clip(MaterialTheme.shapes.small),
                trackColor = MaterialTheme.colorScheme.surfaceVariant,
            )
            Spacer(Modifier.height(10.dp))
            Row {
                Numero("Empenhado", "${qtd(e.empenhado)} ${e.um ?: ""}", Modifier.weight(1f))
                Numero("Baixado", qtd(e.baixado), Modifier.weight(1f))
                Numero("Saldo", qtd(e.saldo), Modifier.weight(1f), alerta = e.saldo > 0)
            }
            Spacer(Modifier.height(6.dp))
            Text(listOfNotNull(e.trt?.let { "Seq. $it" }, e.armazem?.let { "Armazém $it" }, e.lote?.let { "Lote $it" }, e.data).joinToString(" · "),
                style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

// ---------- Aba Engenharia (estrutura e roteiro padrão) ----------

private fun LazyListScope.abaEngenharia(d: DetalhesOp) {
    item {
        Column {
            TituloSecao("Estrutura do produto" + (d.op.revisao?.let { " · revisão $it" } ?: ""))
            d.estruturaVigencia?.let {
                Text("Vigência: $it", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(start = 4.dp))
            }
        }
    }
    if (d.estrutura.isEmpty()) item { Aviso("Nenhuma estrutura vigente para este produto.", Situacao.NEUTRA) }
    items(d.estrutura) { g ->
        val dif = g.empenhado?.let { it - g.necessidade }
        Cartao(preenchimento = PaddingValues(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(listOfNotNull(g.trt?.let { "$it ·" }, g.componente).joinToString(" "), style = MaterialTheme.typography.titleSmall)
                    g.descricao?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                }
                when {
                    dif == null -> Selo("Sem empenho", Situacao.ALERTA)
                    kotlin.math.abs(dif) < 0.0000005 -> Selo("Igual", Situacao.OK)
                    else -> Selo((if (dif > 0) "+" else "") + qtd(dif), if (dif > 0) Situacao.ALERTA else Situacao.NEUTRA)
                }
            }
            Spacer(Modifier.height(10.dp))
            Row {
                Numero("Por peça", "${qtd(g.porPeca, 6)} ${g.um ?: ""}" + if (g.fixa) " (fixa)" else "", Modifier.weight(1f))
                Numero("Necessidade", qtd(g.necessidade), Modifier.weight(1f))
                Numero("Empenhado", g.empenhado?.let { qtd(it) } ?: "—", Modifier.weight(1f))
            }
            vigenciaDoItem(g)?.let {
                Spacer(Modifier.height(6.dp))
                Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            if (g.perda > 0 || g.intermediario) {
                Spacer(Modifier.height(6.dp))
                Text(listOfNotNull(g.perda.takeIf { it > 0 }?.let { "Perda ${qtd(it)}%" }, "intermediário (tem estrutura própria)".takeIf { g.intermediario })
                    .joinToString(" · "), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
    item { TituloSecao("Roteiro de operações" + (d.roteiro?.let { " · $it" } ?: "")) }
    if (d.operacoes.isEmpty()) item { Aviso("Nenhum roteiro (SG2) para este produto.", Situacao.NEUTRA) }
    items(d.operacoes) { o ->
        Cartao(preenchimento = PaddingValues(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("${o.operacao} · ${o.descricao}", style = MaterialTheme.typography.titleSmall, modifier = Modifier.weight(1f))
                if (o.comEstaOp) Selo("Com esta OP", Situacao.OK)
            }
            Spacer(Modifier.height(6.dp))
            Campo("Máquina", listOfNotNull(o.maquina, o.maquinaNome).joinToString(" · ").ifBlank { null })
            Campo("Molde / ferramenta", o.ferramenta?.let { f ->
                listOfNotNull(f, o.ferramentaDescricao, o.cavidadesAtivas?.let { "$it/${o.cavidades ?: it} cav. ativas" }).joinToString(" · ")
            })
            Campo("Recurso", o.recurso)
            Campo("Tipo", o.tipo.ifBlank { null })
            Spacer(Modifier.height(8.dp))
            Row {
                Numero("Setup", qtd(o.setup), Modifier.weight(1f))
                Numero("T. padrão", qtd(o.tempoPadrao, 4), Modifier.weight(1f))
                Numero("Lote padrão", qtd(o.lotePadrao), Modifier.weight(1f))
                Numero("Mão de obra", qtd(o.maoDeObra), Modifier.weight(1f))
            }
        }
    }
}

/** Vigência do componente, só quando tem data: "vale de X até Y", "vale desde X" ou "vale até Y". */
private fun vigenciaDoItem(g: ItemEstrutura): String? = when {
    g.vigenciaInicio != null && g.vigenciaFim != null -> "Vale de ${g.vigenciaInicio} até ${g.vigenciaFim}"
    g.vigenciaInicio != null -> "Vale desde ${g.vigenciaInicio}"
    g.vigenciaFim != null -> "Vale até ${g.vigenciaFim}"
    else -> null
}

// ---------- Peças ----------

@Composable
private fun Campo(rotulo: String, valor: String?) {
    Row(Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
        Text(rotulo, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.weight(1f))
        Text(valor ?: "-", style = MaterialTheme.typography.bodyMedium, textAlign = TextAlign.End, modifier = Modifier.weight(1.3f))
    }
}

@Composable
private fun Numero(rotulo: String, valor: String, modifier: Modifier = Modifier, destaque: Boolean = false, alerta: Boolean = false) {
    Column(modifier) {
        Text(rotulo, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1)
        Text(
            valor,
            style = if (destaque) MaterialTheme.typography.titleLarge else MaterialTheme.typography.titleSmall,
            fontWeight = FontWeight.SemiBold,
            color = when {
                alerta -> CoresOee.atual.alerta
                destaque -> MaterialTheme.colorScheme.primary
                else -> MaterialTheme.colorScheme.onSurface
            },
        )
    }
}

private val brasil = Locale("pt", "BR")

private fun qtd(v: Double, casas: Int = 3): String = NumberFormat.getNumberInstance(brasil).apply { maximumFractionDigits = casas }.format(v)

private fun moeda(v: Double?, casas: Int = 2): String = v?.let {
    NumberFormat.getCurrencyInstance(brasil).apply { minimumFractionDigits = casas; maximumFractionDigits = casas }.format(it)
} ?: "—"

private fun pct(v: Double?): String = v?.let { NumberFormat.getPercentInstance(brasil).apply { maximumFractionDigits = 1 }.format(it) } ?: "—"
