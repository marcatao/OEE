package br.com.alumbra.oee.maquinas

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import br.com.alumbra.oee.R
import br.com.alumbra.oee.api.ErroApi
import br.com.alumbra.oee.ui.Aviso
import br.com.alumbra.oee.ui.BotaoPrincipal
import br.com.alumbra.oee.ui.CoresOee
import br.com.alumbra.oee.ui.Situacao
import br.com.alumbra.oee.ui.corDaLegenda
import com.google.mlkit.vision.codescanner.GmsBarcodeScanning
import kotlinx.coroutines.delay

/**
 * Janela dos diálogos da máquina, desenhada por cima da tela (escurece o fundo e bloqueia os toques nele).
 * Obrigatória = não fecha tocando fora nem com o botão voltar.
 */
@Composable
private fun JanelaMaquina(
    obrigatorio: Boolean,
    aoFechar: () -> Unit,
    conteudo: @Composable () -> Unit,
) {
    BackHandler(enabled = true) { if (!obrigatorio) aoFechar() }
    Box(
        Modifier
            .fillMaxSize()
            .background(CoresOee.atual.fundoEscurecido)
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) {
                if (!obrigatorio) aoFechar()
            }
            .statusBarsPadding()
            .navigationBarsPadding()
            .imePadding()
            .padding(horizontal = 14.dp, vertical = 20.dp),
        contentAlignment = Alignment.Center,
    ) {
        Surface(
            Modifier
                .fillMaxWidth()
                .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) {},
            shape = MaterialTheme.shapes.large,
            color = MaterialTheme.colorScheme.surface,
            shadowElevation = 8.dp,
        ) { conteudo() }
    }
}

@Composable
private fun CabecalhoDialogo(titulo: String, detalhe: String?, situacao: Situacao) {
    val c = CoresOee.atual
    val (cor, fundo) = when (situacao) {
        Situacao.ERRO -> c.erro to c.erroSuave
        Situacao.ALERTA -> c.alerta to c.alertaSuave
        else -> MaterialTheme.colorScheme.primary to MaterialTheme.colorScheme.primaryContainer
    }
    Row(Modifier.fillMaxWidth().background(fundo).padding(18.dp), verticalAlignment = Alignment.CenterVertically) {
        if (situacao != Situacao.OK) {
            Icon(Icons.Filled.Warning, null, tint = cor, modifier = Modifier.size(30.dp))
            Spacer(Modifier.width(12.dp))
        }
        Column {
            Text(titulo, style = MaterialTheme.typography.titleLarge, color = cor)
            if (detalhe != null) Text(detalhe, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurface)
        }
    }
}

/** Parada 98 (sem motivo): obriga escolher uma legenda do tipo parada para AQUELE apontamento. */
@Composable
fun DialogoMotivo(
    apontamento: ApontamentoApp,
    legendas: List<LegendaApp>,
    pendentes: Int,
    ocupado: Boolean,
    erro: String?,
    aoConfirmar: (Int) -> Unit,
) {
    var escolhida by remember(apontamento.id) { mutableStateOf<Int?>(null) }
    JanelaMaquina(obrigatorio = true, aoFechar = {}) {
        Column {
            CabecalhoDialogo(
                titulo = "Máquina parada sem motivo",
                detalhe = "Desde ${apontamento.dia ?: ""} ${apontamento.inicio ?: ""}" +
                    (apontamento.fim?.let { " até $it" } ?: " (em andamento)") +
                    " · ${formatarMinutos(apontamento.minutos)}" +
                    if (pendentes > 1) "\n$pendentes paradas aguardando motivo" else "",
                situacao = Situacao.ERRO,
            )
            Text(
                "Escolha o motivo da parada:",
                style = MaterialTheme.typography.titleMedium,
                modifier = Modifier.padding(start = 18.dp, end = 18.dp, top = 16.dp, bottom = 8.dp),
            )
            LazyColumn(
                Modifier.heightIn(max = 360.dp),
                contentPadding = PaddingValues(horizontal = 14.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                items(legendas, key = { it.id }) { l ->
                    val marcada = escolhida == l.id
                    val cor = corDaLegenda(l.cor, MaterialTheme.colorScheme.outline)
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .clip(MaterialTheme.shapes.medium)
                            .background(if (marcada) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surfaceVariant)
                            .border(
                                2.dp,
                                if (marcada) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surfaceVariant,
                                MaterialTheme.shapes.medium,
                            )
                            .clickable { escolhida = l.id }
                            .padding(horizontal = 14.dp, vertical = 14.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Box(Modifier.size(18.dp).clip(CircleShape).background(cor))
                        Spacer(Modifier.width(12.dp))
                        Text(l.codigo, style = MaterialTheme.typography.titleMedium, modifier = Modifier.width(56.dp))
                        Text(l.descricao, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
                    }
                }
            }
            if (legendas.isEmpty()) {
                Aviso("Nenhuma legenda de parada cadastrada no portal.", Situacao.ALERTA, Modifier.padding(horizontal = 14.dp))
            }
            Column(Modifier.padding(14.dp)) {
                if (erro != null) {
                    Aviso(erro, Situacao.ERRO)
                    Spacer(Modifier.height(10.dp))
                }
                BotaoPrincipal(
                    texto = "Confirmar motivo",
                    aoTocar = { escolhida?.let(aoConfirmar) },
                    habilitado = escolhida != null,
                    carregando = ocupado,
                )
            }
        }
    }
}

/** Escolher a OP da máquina: busca pelo número/produto ou leitura do código de barras. */
@Composable
fun DialogoOp(
    repositorio: RepositorioMaquinas,
    obrigatorio: Boolean,
    opAtual: String?,
    ocupado: Boolean,
    erro: String?,
    aoConfirmar: (String) -> Unit,
    aoFechar: () -> Unit,
) {
    val contexto = LocalContext.current
    var busca by remember { mutableStateOf("") }
    var ops by remember { mutableStateOf<List<OpResumo>?>(null) }
    var erroBusca by remember { mutableStateOf<String?>(null) }
    var escolhida by remember { mutableStateOf<String?>(null) }
    var buscar by remember { mutableIntStateOf(0) }

    LaunchedEffect(busca, buscar) {
        delay(400) // espera parar de digitar
        try {
            ops = repositorio.buscarOps(busca)
            erroBusca = null
        } catch (e: ErroApi) {
            erroBusca = e.message
        }
    }

    JanelaMaquina(obrigatorio = obrigatorio, aoFechar = aoFechar) {
        Column {
            CabecalhoDialogo(
                titulo = if (obrigatorio) "Máquina sem ordem de produção" else "Trocar ordem de produção",
                detalhe = if (obrigatorio) "Informe a OP que está rodando nesta máquina." else "OP atual: ${opAtual ?: "nenhuma"}",
                situacao = if (obrigatorio) Situacao.ALERTA else Situacao.OK,
            )
            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                OutlinedButton(
                    onClick = {
                        GmsBarcodeScanning.getClient(contexto).startScan()
                            .addOnSuccessListener { codigo ->
                                val lido = codigo.rawValue?.trim().orEmpty()
                                if (lido.isNotEmpty()) {
                                    busca = lido
                                    escolhida = lido
                                }
                            }
                            .addOnFailureListener { erroBusca = "Não foi possível ler o código de barras." }
                    },
                    modifier = Modifier.fillMaxWidth().height(52.dp),
                    shape = MaterialTheme.shapes.medium,
                ) {
                    Icon(painterResource(R.drawable.ic_codigo_barras), null, Modifier.size(22.dp))
                    Spacer(Modifier.width(10.dp))
                    Text("Ler código de barras da OP")
                }
                OutlinedTextField(
                    value = busca,
                    onValueChange = { busca = it; escolhida = null },
                    modifier = Modifier.fillMaxWidth(),
                    placeholder = { Text("Número da OP ou produto") },
                    leadingIcon = { Icon(Icons.Filled.Search, null) },
                    singleLine = true,
                    shape = MaterialTheme.shapes.medium,
                )
                if (erroBusca != null) Aviso(erroBusca!!, Situacao.ERRO)
            }
            LazyColumn(
                Modifier.heightIn(max = 300.dp),
                contentPadding = PaddingValues(horizontal = 14.dp),
                verticalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                items(ops.orEmpty(), key = { it.id }) { op ->
                    val marcada = escolhida == op.numero
                    Column(
                        Modifier
                            .fillMaxWidth()
                            .clip(MaterialTheme.shapes.medium)
                            .background(if (marcada) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surfaceVariant)
                            .border(2.dp, if (marcada) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surfaceVariant, MaterialTheme.shapes.medium)
                            .clickable { escolhida = op.numero }
                            .padding(12.dp),
                    ) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(op.numero, style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                            Text(op.statusRotulo, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        Text(
                            listOfNotNull(op.produto, op.descricao).joinToString(" · "),
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }
            if (ops == null && erroBusca == null) {
                CircularProgressIndicator(Modifier.align(Alignment.CenterHorizontally).padding(12.dp))
            } else if (ops?.isEmpty() == true) {
                Text(
                    "Nenhuma OP aberta encontrada.",
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.fillMaxWidth().padding(12.dp),
                    textAlign = TextAlign.Center,
                )
            }
            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                if (erro != null) Aviso(erro, Situacao.ERRO)
                BotaoPrincipal(
                    texto = escolhida?.let { "Usar a OP $it" } ?: "Escolha uma OP",
                    aoTocar = { escolhida?.let(aoConfirmar) },
                    habilitado = escolhida != null,
                    carregando = ocupado,
                )
                if (!obrigatorio) {
                    TextButton(onClick = aoFechar, modifier = Modifier.align(Alignment.CenterHorizontally)) { Text("Cancelar") }
                }
            }
        }
    }
}

/** Cavidades ativas do molde (de 0 até as cavidades do Protheus). */
@Composable
fun DialogoCavidades(
    molde: MoldeApp,
    ocupado: Boolean,
    erro: String?,
    aoSalvar: (Int) -> Unit,
    aoFechar: () -> Unit,
) {
    var valor by remember(molde.id) { mutableIntStateOf(molde.cavidadesAtivas) }
    JanelaMaquina(obrigatorio = false, aoFechar = aoFechar) {
        Column {
            CabecalhoDialogo("Cavidades ativas", "Molde ${molde.codigo} · ${molde.descricao}", Situacao.OK)
            Row(
                Modifier.fillMaxWidth().padding(vertical = 24.dp),
                horizontalArrangement = Arrangement.Center,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                FilledTonalButton(onClick = { if (valor > 0) valor-- }, enabled = valor > 0, modifier = Modifier.size(64.dp),
                    shape = CircleShape, contentPadding = PaddingValues(0.dp)) {
                    Text("−", fontSize = 30.sp)
                }
                Column(Modifier.width(140.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    Text("$valor", fontSize = 56.sp, fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.primary)
                    Text("de ${molde.cavidades}", style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                FilledTonalButton(onClick = { if (valor < molde.cavidades) valor++ }, enabled = valor < molde.cavidades,
                    modifier = Modifier.size(64.dp), shape = CircleShape, contentPadding = PaddingValues(0.dp)) {
                    Text("+", fontSize = 30.sp)
                }
            }
            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                if (erro != null) Aviso(erro, Situacao.ERRO)
                BotaoPrincipal("Salvar cavidades", { aoSalvar(valor) }, habilitado = valor != molde.cavidadesAtivas, carregando = ocupado)
                TextButton(onClick = aoFechar, modifier = Modifier.align(Alignment.CenterHorizontally)) { Text("Cancelar") }
            }
        }
    }
}

/** "25 min", "1 h 05 min". */
fun formatarMinutos(minutos: Double): String {
    val total = minutos.toLong()
    return if (total < 60) "$total min" else "${total / 60} h ${"%02d".format(total % 60)} min"
}
