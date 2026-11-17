package br.com.alumbra.oee.os

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Tab
import androidx.compose.material3.TabRow
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import br.com.alumbra.oee.api.ErroApi
import br.com.alumbra.oee.ui.Aviso
import br.com.alumbra.oee.ui.BarraTopo
import br.com.alumbra.oee.ui.Cartao
import br.com.alumbra.oee.ui.CoresOee
import br.com.alumbra.oee.ui.Selo
import br.com.alumbra.oee.ui.Situacao
import kotlinx.coroutines.launch

/** Central das Ordens de serviço: solicitações (assumir), minhas OS e OS para avaliar. */
@Composable
fun TelaOrdensServico(
    repositorio: RepositorioOs,
    resumo: ResumoOs?,
    aoVoltar: () -> Unit,
    aoAbrirOs: (Int) -> Unit,
    aoAbrirSs: () -> Unit,
    aoAvaliar: (Int) -> Unit,
    aoAtualizarResumo: () -> Unit,
    aoPerderAcesso: (String) -> Unit,
) {
    var aba by rememberSaveable { mutableIntStateOf(0) }
    var pendentes by remember { mutableStateOf<List<SsApp>?>(null) }
    var minhasSs by remember { mutableStateOf<List<SsApp>>(emptyList()) }
    var ordens by remember { mutableStateOf<List<OsApp>?>(null) }
    var avaliar by remember { mutableStateOf<List<OsApp>?>(null) }
    var erro by remember { mutableStateOf<String?>(null) }
    var ocupado by remember { mutableStateOf<Int?>(null) }
    var recarregar by remember { mutableIntStateOf(0) }
    val escopo = rememberCoroutineScope()

    suspend fun <T> tentar(bloco: suspend () -> T): T? = try {
        bloco().also { erro = null }
    } catch (e: ErroApi) {
        if (e.semAcesso) aoPerderAcesso(e.message ?: "") else erro = e.message
        null
    }

    LaunchedEffect(aba, recarregar, resumo?.pendentes?.size) {
        when (aba) {
            0 -> tentar { repositorio.solicitacoes() }?.let { pendentes = it.first; minhasSs = it.second }
            1 -> tentar { repositorio.ordens() }?.let { ordens = it }
            2 -> tentar { repositorio.avaliacoes() }?.let { avaliar = it }
        }
    }

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        BarraTopo("Ordens de serviço", resumo?.mantenedor?.let { "Mantenedor: $it" }, aoVoltar) {
            IconButton(onClick = { recarregar++; aoAtualizarResumo() }) { Icon(Icons.Filled.Refresh, "Atualizar", tint = CoresOee.atual.heroiTexto) }
        }
        TabRow(selectedTabIndex = aba, containerColor = MaterialTheme.colorScheme.surface) {
            listOf(
                "Solicitações" + (resumo?.pendentes?.size?.takeIf { it > 0 }?.let { " ($it)" } ?: ""),
                "Minhas OS" + (resumo?.minhasAbertas?.takeIf { it > 0 }?.let { " ($it)" } ?: ""),
                "Avaliar" + (resumo?.avaliacoes?.takeIf { it > 0 }?.let { " ($it)" } ?: ""),
            ).forEachIndexed { i, t -> Tab(selected = aba == i, onClick = { aba = i }, text = { Text(t, maxLines = 1) }) }
        }
        if (erro != null) Aviso(erro!!, Situacao.ERRO, Modifier.padding(16.dp))
        if (resumo != null && resumo.mantenedor == null && (resumo.podeAprovar || resumo.podeExecutar)) {
            Aviso("Seu login não está ligado a um mantenedor: para assumir e apontar horas, peça a ligação em Ordens de serviço › Mantenedores no portal.",
                Situacao.ALERTA, Modifier.padding(horizontal = 16.dp, vertical = 8.dp))
        }

        Box(Modifier.weight(1f)) {
            LazyColumn(
                contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 12.dp, bottom = 96.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
                modifier = Modifier.navigationBarsPadding(),
            ) {
                when (aba) {
                    0 -> {
                        val lista = pendentes
                        if (resumo?.podeAprovar == true) {
                            item { Text("AGUARDANDO UM MANTENEDOR ASSUMIR", style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant) }
                            if (lista != null && lista.isEmpty()) item { Vazio("Nenhuma solicitação aguardando.") }
                            items(lista.orEmpty(), key = { "p${it.id}" }) { ss ->
                                CartaoSs(ss, assumindo = ocupado == ss.id) {
                                    escopo.launch {
                                        ocupado = ss.id
                                        tentar { repositorio.assumir(ss.id) }?.let { aoAtualizarResumo(); aoAbrirOs(it.id) }
                                        ocupado = null
                                        recarregar++
                                    }
                                }
                            }
                        }
                        if (minhasSs.isNotEmpty()) {
                            item { Text("MINHAS SOLICITAÇÕES", style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(top = 8.dp)) }
                            items(minhasSs, key = { "m${it.id}" }) { CartaoSs(it, assumindo = false, aoAssumir = null) }
                        }
                    }
                    1 -> {
                        if (ordens?.isEmpty() == true) item { Vazio("Nenhuma OS em aberto para você.") }
                        items(ordens.orEmpty(), key = { it.id }) { CartaoOs(it) { aoAbrirOs(it.id) } }
                    }
                    else -> {
                        item {
                            Text("OS concluídas por você: abra com o solicitante para ele avaliar e assinar no celular.",
                                style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        if (avaliar?.isEmpty() == true) item { Vazio("Nenhuma OS esperando avaliação.") }
                        items(avaliar.orEmpty(), key = { it.id }) { CartaoOs(it) { aoAvaliar(it.id) } }
                    }
                }
            }
            if (resumo?.podeSolicitar == true) {
                ExtendedFloatingActionButton(
                    onClick = aoAbrirSs,
                    icon = { Icon(Icons.Filled.Add, null) },
                    text = { Text("Abrir SS") },
                    modifier = Modifier.align(Alignment.BottomEnd).navigationBarsPadding().padding(16.dp),
                    containerColor = MaterialTheme.colorScheme.primary,
                    contentColor = MaterialTheme.colorScheme.onPrimary,
                )
            }
        }
    }
}

@Composable
private fun Vazio(texto: String) {
    Text(texto, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(vertical = 16.dp))
}

@Composable
fun CartaoSs(ss: SsApp, assumindo: Boolean, aoAssumir: (() -> Unit)?) {
    val c = CoresOee.atual
    Cartao(preenchimento = PaddingValues(0.dp)) {
        Row(Modifier.height(IntrinsicSize.Min)) {
            Box(Modifier.width(6.dp).fillMaxHeight().background(if (ss.maquinaParada) c.erro else MaterialTheme.colorScheme.primary))
            Column(Modifier.weight(1f).padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("SS ${ss.numero}", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.weight(1f))
                    if (aoAssumir == null) Selo(ss.os?.let { "OS $it" } ?: ss.statusRotulo, Situacao.NEUTRA)
                }
                Text(ss.titulo, style = MaterialTheme.typography.titleMedium)
                Text(ss.descricao, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 3, overflow = TextOverflow.Ellipsis)
                Text(
                    listOfNotNull(ss.maquina, ss.area, ss.solicitante?.let { "por $it" }, ss.abertaEm).joinToString(" · "),
                    style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                if (ss.motivoRejeicao != null) Aviso("Rejeitada: ${ss.motivoRejeicao}", Situacao.ERRO)
                SelosOs(ss.prioridade, ss.maquinaParada)
                if (aoAssumir != null) {
                    Spacer(Modifier.height(6.dp))
                    FilledTonalButton(onClick = aoAssumir, enabled = !assumindo, modifier = Modifier.fillMaxWidth().height(48.dp),
                        shape = MaterialTheme.shapes.medium) {
                        Text(if (assumindo) "Assumindo…" else "Assumir esta SS")
                    }
                }
            }
        }
    }
}

@Composable
fun CartaoOs(os: OsApp, aoTocar: () -> Unit) {
    val c = CoresOee.atual
    Cartao(Modifier.clip(MaterialTheme.shapes.large).clickable(onClick = aoTocar), preenchimento = PaddingValues(0.dp)) {
        Row(Modifier.height(IntrinsicSize.Min)) {
            Box(Modifier.width(6.dp).fillMaxHeight().background(if (os.maquinaParada) c.erro else MaterialTheme.colorScheme.primary))
            Column(Modifier.weight(1f).padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("OS ${os.numero}", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.weight(1f))
                    Selo(os.statusRotulo, Situacao.NEUTRA)
                }
                Text(os.titulo, style = MaterialTheme.typography.titleMedium)
                Text(listOfNotNull(os.maquina, os.area, os.solicitante?.let { "Solicitante: $it" }).joinToString(" · "),
                    style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                SelosOs(os.prioridade, os.maquinaParada)
            }
            Text("›", style = MaterialTheme.typography.headlineMedium, color = MaterialTheme.colorScheme.outline,
                modifier = Modifier.align(Alignment.CenterVertically).padding(end = 12.dp))
        }
    }
}
