package br.com.alumbra.oee.maquinas

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
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
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.repeatOnLifecycle
import br.com.alumbra.oee.api.ErroApi
import br.com.alumbra.oee.ui.Aviso
import br.com.alumbra.oee.ui.BarraTopo
import br.com.alumbra.oee.ui.Cartao
import br.com.alumbra.oee.ui.CoresOee
import br.com.alumbra.oee.ui.Selo
import br.com.alumbra.oee.ui.Situacao
import br.com.alumbra.oee.ui.corDaLegenda
import kotlinx.coroutines.delay

/** Lista das máquinas ativas com IoT ativo; atualiza sozinha a cada 30 s. */
@Composable
fun TelaMaquinas(
    repositorio: RepositorioMaquinas,
    aoVoltar: () -> Unit,
    aoAbrir: (MaquinaResumo) -> Unit,
    aoPerderAcesso: (String) -> Unit,
) {
    var maquinas by remember { mutableStateOf<List<MaquinaResumo>?>(null) }
    var erro by remember { mutableStateOf<String?>(null) }
    var busca by remember { mutableStateOf("") }
    var carregando by remember { mutableStateOf(false) }
    var recarregar by remember { mutableIntStateOf(0) }
    val ciclo = LocalLifecycleOwner.current.lifecycle

    LaunchedEffect(recarregar) {
        ciclo.repeatOnLifecycle(Lifecycle.State.RESUMED) {
            while (true) {
                carregando = true
                try {
                    maquinas = repositorio.lista()
                    erro = null
                } catch (e: ErroApi) {
                    if (e.semAcesso) aoPerderAcesso(e.message ?: "") else erro = e.message
                } finally {
                    carregando = false
                }
                delay(30_000)
            }
        }
    }

    ConteudoMaquinas(maquinas, erro, busca, { busca = it }, carregando, aoVoltar, { recarregar++ }, aoAbrir)
}

@Composable
fun ConteudoMaquinas(
    maquinas: List<MaquinaResumo>?,
    erro: String?,
    busca: String,
    aoBuscar: (String) -> Unit,
    carregando: Boolean,
    aoVoltar: () -> Unit,
    aoAtualizar: () -> Unit,
    aoAbrir: (MaquinaResumo) -> Unit,
) {
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        BarraTopo(
            titulo = "Máquinas",
            subtitulo = maquinas?.let { "${it.size} ativas com IoT" } ?: "Carregando…",
            aoVoltar = aoVoltar,
        ) {
            if (carregando) {
                CircularProgressIndicator(Modifier.padding(12.dp).width(22.dp).height(22.dp), color = CoresOee.atual.heroiTexto, strokeWidth = 2.dp)
            } else {
                IconButton(onClick = aoAtualizar) { Icon(Icons.Filled.Refresh, "Atualizar", tint = CoresOee.atual.heroiTexto) }
            }
        }

        OutlinedTextField(
            value = busca,
            onValueChange = aoBuscar,
            modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp),
            placeholder = { Text("Buscar máquina, código ou OP") },
            leadingIcon = { Icon(Icons.Filled.Search, null) },
            singleLine = true,
            shape = MaterialTheme.shapes.medium,
        )

        if (erro != null) {
            Aviso(texto = erro, situacao = Situacao.ERRO, modifier = Modifier.padding(horizontal = 16.dp))
            Spacer(Modifier.height(8.dp))
        }

        val termo = busca.trim().lowercase()
        val filtradas = maquinas.orEmpty().filter {
            termo.isEmpty() || it.nome.lowercase().contains(termo) || it.codigo.lowercase().contains(termo) ||
                (it.op?.numero?.contains(termo) == true)
        }
        // Primeiro as que pedem ação: paradas sem motivo, depois sem OP.
        val ordenadas = filtradas.sortedWith(compareByDescending<MaquinaResumo> { it.semMotivo > 0 }.thenBy { it.op != null }.thenBy { it.nome })

        if (maquinas != null && ordenadas.isEmpty()) {
            Text(
                if (termo.isEmpty()) "Nenhuma máquina ativa com IoT ativo." else "Nenhuma máquina encontrada.",
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(24.dp),
            )
        }
        LazyColumn(
            contentPadding = PaddingValues(start = 16.dp, end = 16.dp, bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
            modifier = Modifier.navigationBarsPadding(),
        ) {
            items(ordenadas, key = { it.id }) { CartaoMaquina(it) { aoAbrir(it) } }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun CartaoMaquina(m: MaquinaResumo, aoTocar: () -> Unit) {
    val c = CoresOee.atual
    val corFaixa = when {
        m.semMotivo > 0 -> c.erro
        m.legenda != null -> corDaLegenda(m.legenda.cor, MaterialTheme.colorScheme.outline)
        m.situacao == "produzindo" -> c.ok
        else -> MaterialTheme.colorScheme.outline
    }
    Cartao(Modifier.clip(MaterialTheme.shapes.large).clickable(onClick = aoTocar), preenchimento = PaddingValues(0.dp)) {
        Row(Modifier.height(IntrinsicSize.Min)) {
            Box(Modifier.width(6.dp).fillMaxHeight().background(corFaixa))
            Column(Modifier.weight(1f).padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(m.nome, style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text(
                            listOf(m.codigo, m.local).filter { it.isNotBlank() }.joinToString(" · "),
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                    }
                    Text("›", style = MaterialTheme.typography.headlineMedium, color = MaterialTheme.colorScheme.outline)
                }
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    if (m.semMotivo > 0) Selo("Informar motivo" + if (m.semMotivo > 1) " (${m.semMotivo})" else "", Situacao.ERRO)
                    when (m.situacao) {
                        "produzindo" -> Selo(m.legenda?.let { "${it.codigo} · ${it.descricao}" } ?: "Produzindo", Situacao.OK)
                        "parada" -> Selo(m.legenda?.let { "${it.codigo} · ${it.descricao}" } ?: "Parada", Situacao.ERRO)
                        else -> Selo("Sem apontamento", Situacao.NEUTRA)
                    }
                    if (m.op != null) Selo("OP ${m.op.numero}", Situacao.NEUTRA) else Selo("Sem OP", Situacao.ALERTA)
                    // Parada com SS/OS aberta pela legenda: número e situação, para acompanhar o desfecho.
                    m.chamado?.let { Selo("${it.numero} · ${it.rotulo}", it.situacao) }
                }
            }
        }
    }
}

