package br.com.alumbra.oee.maquinas

import android.app.Activity
import android.view.WindowManager
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.ui.text.drawText
import androidx.compose.foundation.background
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
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Build
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.repeatOnLifecycle
import br.com.alumbra.oee.R
import br.com.alumbra.oee.api.ErroApi
import br.com.alumbra.oee.ui.Aviso
import br.com.alumbra.oee.ui.BarraTopo
import br.com.alumbra.oee.ui.Cartao
import br.com.alumbra.oee.ui.CoresOee
import br.com.alumbra.oee.ui.IconeRedondo
import br.com.alumbra.oee.ui.Selo
import br.com.alumbra.oee.ui.Situacao
import br.com.alumbra.oee.ui.TituloSecao
import br.com.alumbra.oee.ui.corDaLegenda
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.time.OffsetDateTime
import java.util.Date
import java.util.Locale

/**
 * Tela da máquina (operador): fica com a tela sempre acesa e se atualiza sozinha. Parada "sem motivo" (98) apita e
 * obriga escolher o motivo; máquina sem OP avisa e obriga escolher a OP. Também troca a OP e as cavidades do molde.
 */
@Composable
fun TelaMaquina(
    repositorio: RepositorioMaquinas,
    maquinaId: Int,
    nomeInicial: String,
    podeAlterar: Boolean,
    aoVoltar: () -> Unit,
    aoPerderAcesso: (String) -> Unit,
    aoDetalhesOp: (OpResumo) -> Unit = {},
) {
    val contexto = LocalContext.current
    val escopo = rememberCoroutineScope()
    var detalhe by remember { mutableStateOf<DetalheMaquina?>(null) }
    var erro by remember { mutableStateOf<String?>(null) }
    var erroAcao by remember { mutableStateOf<String?>(null) }
    var ocupado by remember { mutableStateOf(false) }
    var atualizadoEm by remember { mutableLongStateOf(0L) }
    var recarregar by remember { mutableIntStateOf(0) }
    var trocarOp by remember { mutableStateOf(false) }
    var molde by remember { mutableStateOf<MoldeApp?>(null) }
    val alarme = remember { Alarme(contexto) }
    val ciclo = LocalLifecycleOwner.current.lifecycle

    // Tela sempre acesa enquanto a máquina estiver aberta.
    val vista = LocalView.current
    DisposableEffect(Unit) {
        vista.keepScreenOn = true
        onDispose {
            vista.keepScreenOn = false
            alarme.liberar()
        }
    }

    // Atualiza sozinha (só com o app na frente).
    LaunchedEffect(maquinaId, recarregar) {
        ciclo.repeatOnLifecycle(Lifecycle.State.RESUMED) {
            while (true) {
                try {
                    detalhe = repositorio.detalhe(maquinaId)
                    atualizadoEm = System.currentTimeMillis()
                    erro = null
                } catch (e: ErroApi) {
                    if (e.semAcesso) {
                        aoPerderAcesso(e.message ?: "")
                    } else {
                        erro = e.message
                        // Sem rede ao abrir: mostra o último dado guardado no celular.
                        if (detalhe == null) detalhe = repositorio.detalheGuardado(maquinaId)
                    }
                }
                delay((detalhe?.segundosAtualizacao ?: 10) * 1000L)
            }
        }
    }

    val d = detalhe
    val pendente = d?.semMotivo?.firstOrNull()
    val semOp = d != null && d.op == null && pendente == null

    // Alarme: parada sem motivo apita sem parar; sem OP avisa algumas vezes.
    LaunchedEffect(pendente?.id, podeAlterar) {
        if (pendente != null) {
            ciclo.repeatOnLifecycle(Lifecycle.State.RESUMED) {
                while (true) {
                    alarme.urgente()
                    delay(3_000)
                }
            }
        }
    }
    LaunchedEffect(semOp) {
        if (semOp) repeat(3) { alarme.aviso(); delay(700) }
    }

    // Economia de bateria: sem toque por 1 min, a tela escurece; toque ou alerta volta ao brilho total.
    val janela = (contexto as? Activity)?.window
    var ultimoToque by remember { mutableLongStateOf(System.currentTimeMillis()) }
    var esmaecida by remember { mutableStateOf(false) }
    val alerta = pendente != null || semOp
    LaunchedEffect(ultimoToque, alerta) {
        esmaecida = false
        if (!alerta) {
            delay(SEGUNDOS_PARA_ESMAECER * 1000L)
            esmaecida = true
        }
    }
    DisposableEffect(esmaecida, alerta) {
        janela?.let { w ->
            w.attributes = w.attributes.apply {
                screenBrightness = when {
                    alerta -> WindowManager.LayoutParams.BRIGHTNESS_OVERRIDE_FULL
                    esmaecida -> 0.02f
                    else -> WindowManager.LayoutParams.BRIGHTNESS_OVERRIDE_NONE
                }
            }
        }
        onDispose {
            janela?.let { w -> w.attributes = w.attributes.apply { screenBrightness = WindowManager.LayoutParams.BRIGHTNESS_OVERRIDE_NONE } }
        }
    }

    fun executar(acao: suspend () -> DetalheMaquina, aoTerminar: () -> Unit = {}) {
        escopo.launch {
            ocupado = true
            erroAcao = null
            try {
                detalhe = acao()
                atualizadoEm = System.currentTimeMillis()
                aoTerminar()
            } catch (e: ErroApi) {
                if (e.semAcesso) aoPerderAcesso(e.message ?: "") else erroAcao = e.message
            } finally {
                ocupado = false
            }
        }
    }

    Box(
        Modifier.fillMaxSize().pointerInput(Unit) {
            awaitPointerEventScope {
                while (true) {
                    awaitPointerEvent(PointerEventPass.Initial)
                    ultimoToque = System.currentTimeMillis()
                }
            }
        },
    ) {
    ConteudoMaquina(
        nomeInicial = nomeInicial,
        detalhe = d,
        erro = erro,
        atualizadoEm = atualizadoEm,
        podeAlterar = podeAlterar,
        aoVoltar = aoVoltar,
        aoAtualizar = { recarregar++ },
        aoTrocarOp = { erroAcao = null; trocarOp = true },
        aoAlterarCavidades = { erroAcao = null; molde = it },
        aoDetalhesOp = aoDetalhesOp,
    )
    if (esmaecida && !alerta) {
        // Tela escura: o primeiro toque só acende.
        Box(
            Modifier.fillMaxSize().background(Color.Black.copy(alpha = .6f))
                .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { ultimoToque = System.currentTimeMillis() },
            contentAlignment = Alignment.Center,
        ) {
            Text("Toque para acender", color = Color.White.copy(alpha = .7f), style = MaterialTheme.typography.titleMedium)
        }
    }
    }

    if (d != null && podeAlterar) {
        when {
            pendente != null -> DialogoMotivo(pendente, d.legendasMotivo, d.semMotivo.size, ocupado, erroAcao) { legenda ->
                executar({ repositorio.informarMotivo(pendente.id, legenda) })
            }
            semOp || trocarOp -> DialogoOp(
                repositorio = repositorio,
                obrigatorio = semOp,
                opAtual = d.op?.resumo?.numero,
                ocupado = ocupado,
                erro = erroAcao,
                aoConfirmar = { numero, modo -> executar({ repositorio.trocarOp(d.id, numero, modo) }) { trocarOp = false } },
                aoFechar = { trocarOp = false },
            )
            molde != null -> DialogoCavidades(molde!!, ocupado, erroAcao, { n ->
                executar({ repositorio.alterarCavidades(molde!!.id, d.id, n) }) { molde = null }
            }, { molde = null })
        }
    }
}

/** Segundos sem toque até a tela da máquina escurecer. */
private const val SEGUNDOS_PARA_ESMAECER = 60

@Composable
fun ConteudoMaquina(
    nomeInicial: String,
    detalhe: DetalheMaquina?,
    erro: String?,
    atualizadoEm: Long,
    podeAlterar: Boolean,
    aoVoltar: () -> Unit,
    aoAtualizar: () -> Unit,
    aoTrocarOp: () -> Unit,
    aoAlterarCavidades: (MoldeApp) -> Unit,
    aoDetalhesOp: (OpResumo) -> Unit = {},
    agora: Long = System.currentTimeMillis(),
) {
    val hora = SimpleDateFormat("HH:mm:ss", Locale("pt", "BR"))
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        BarraTopo(
            titulo = detalhe?.nome ?: nomeInicial,
            subtitulo = detalhe?.let { listOf(it.codigo, it.local).filter { s -> s.isNotBlank() }.joinToString(" · ") },
            aoVoltar = aoVoltar,
        ) {
            IconButton(onClick = aoAtualizar) { Icon(Icons.Filled.Refresh, "Atualizar", tint = CoresOee.atual.heroiTexto) }
        }
        if (detalhe == null) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                if (erro != null) Aviso(erro, Situacao.ERRO, Modifier.padding(16.dp)) else CircularProgressIndicator()
            }
            return
        }

        Column(
            Modifier.verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding(),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            if (erro != null) Aviso("$erro Mostrando os últimos dados recebidos.", Situacao.ALERTA)
            if (detalhe.semMotivo.isNotEmpty()) FaixaPiscante("${detalhe.semMotivo.size} parada(s) sem motivo — informe o motivo", Situacao.ERRO)
            if (detalhe.op == null) FaixaPiscante("Máquina sem ordem de produção — informe a OP", Situacao.ALERTA)

            CartaoSituacao(detalhe, atualizadoEm, hora)
            CartaoLinhaDoTempo(detalhe, agora)
            detalhe.producao?.let { CartaoProducao(it, agora, detalhe.situacao == "produzindo") }

            TituloSecao("Ordem de produção")
            CartaoOp(detalhe.op, podeAlterar, aoTrocarOp, aoDetalhesOp)
            if (detalhe.op != null) {
                CartaoMoldes(detalhe.op.moldes, podeAlterar, aoAlterarCavidades)
                CartaoRoteiro(detalhe.op)
            }

            TituloSecao("Apontamentos reais do turno de hoje")
            if (detalhe.apontamentos.isEmpty()) {
                Text("Sem apontamentos no turno de hoje.", color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            detalhe.apontamentos.forEach { LinhaApontamento(it) }
        }
    }
}

@Composable
private fun FaixaPiscante(texto: String, situacao: Situacao) {
    val transicao = rememberInfiniteTransition(label = "pisca")
    val alfa by transicao.animateFloat(1f, .45f, infiniteRepeatable(tween(600), RepeatMode.Reverse), label = "alfa")
    val c = CoresOee.atual
    val cor = if (situacao == Situacao.ERRO) c.erro else c.alerta
    Row(
        Modifier.fillMaxWidth().clip(MaterialTheme.shapes.medium).background(cor).alpha(alfa).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text("⚠", style = MaterialTheme.typography.titleLarge, color = c.heroiTexto)
        Spacer(Modifier.width(10.dp))
        Text(texto, style = MaterialTheme.typography.titleMedium, color = c.heroiTexto)
    }
}

@Composable
private fun CartaoSituacao(d: DetalheMaquina, atualizadoEm: Long, hora: SimpleDateFormat) {
    val produzindo = d.situacao == "produzindo"
    val cor = corDaLegenda(d.legenda?.cor, if (produzindo) CoresOee.atual.ok else CoresOee.atual.erro)
    Cartao(preenchimento = PaddingValues(0.dp)) {
        Row(Modifier.height(IntrinsicSize.Min)) {
            Box(Modifier.width(8.dp).fillMaxHeight().background(cor))
            Column(Modifier.weight(1f).padding(16.dp)) {
                Text(
                    when (d.situacao) { "produzindo" -> "PRODUZINDO"; "parada" -> "PARADA"; else -> "SEM APONTAMENTO" },
                    style = MaterialTheme.typography.labelSmall,
                    color = if (produzindo) CoresOee.atual.ok else CoresOee.atual.erro,
                )
                Text(
                    d.legenda?.let { "${it.codigo} · ${it.descricao}" } ?: "—",
                    style = MaterialTheme.typography.headlineMedium,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
                if (d.situacaoDesde != null) {
                    Text("Desde ${d.situacaoDesde}", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                // Parada com SS/OS aberta pela legenda: o operador acompanha o número e a situação.
                d.chamado?.let {
                    Spacer(Modifier.height(8.dp))
                    Selo("${it.numero} · ${it.rotulo}", it.situacao)
                }
                Spacer(Modifier.height(8.dp))
                Text(
                    if (atualizadoEm > 0) "Atualizado às ${hora.format(Date(atualizadoEm))} · a cada ${d.segundosAtualizacao} s" else "",
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}

/** Barra de HOJE, só dentro do turno da máquina (o portal manda o período), com as cores das legendas e a marca de "agora". */
@Composable
private fun CartaoLinhaDoTempo(d: DetalheMaquina, agora: Long) {
    val inicioJanela = paraMs(d.periodoDe) ?: (agora - 12 * 3_600_000L)
    val fimJanela = paraMs(d.periodoAte) ?: (agora + 12 * 3_600_000L)
    val janela = (fimJanela - inicioJanela).coerceAtLeast(1L)
    val vazio = MaterialTheme.colorScheme.surfaceVariant
    val padrao = MaterialTheme.colorScheme.outline
    val corAgora = MaterialTheme.colorScheme.onSurface
    val segmentos = d.apontamentos.mapNotNull { a ->
        val ini = paraMs(a.inicioUtc) ?: return@mapNotNull null
        val fim = paraMs(a.fimUtc) ?: agora
        Triple(ini.coerceAtLeast(inicioJanela), fim.coerceAtMost(minOf(agora, fimJanela)), corDaLegenda(a.legenda?.cor, padrao))
    }.filter { it.second > it.first }
    val formato = SimpleDateFormat("HH:mm", Locale("pt", "BR"))

    Cartao(preenchimento = PaddingValues(16.dp)) {
        Text(d.periodoRotulo, style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(10.dp))
        Canvas(Modifier.fillMaxWidth().height(34.dp).clip(MaterialTheme.shapes.small)) {
            drawRect(vazio)
            segmentos.forEach { (ini, fim, cor) ->
                val x = (ini - inicioJanela).toFloat() / janela * size.width
                val w = ((fim - ini).toFloat() / janela * size.width).coerceAtLeast(1f)
                drawRect(cor, topLeft = Offset(x, 0f), size = Size(w, size.height))
            }
            if (agora in inicioJanela..fimJanela) {
                val x = (agora - inicioJanela).toFloat() / janela * size.width
                drawRect(corAgora, topLeft = Offset(x - 1.5f, 0f), size = Size(3f, size.height))
            }
        }
        Spacer(Modifier.height(4.dp))
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            (0..4).forEach { i ->
                Text(
                    formato.format(Date(if (i == 4) fimJanela - 60_000 else inicioJanela + i * janela / 4)),
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}

/**
 * Produção do turno: peças por minuto agora (em destaque), total do turno e barras de peças por hora (a hora atual
 * destacada). Com meta (pç/min), uma linha tracejada na altura de meta × 60 por hora.
 */
@Composable
private fun CartaoProducao(p: ProducaoApp, agora: Long, produzindo: Boolean) {
    val corBarra = MaterialTheme.colorScheme.primary
    val corBarraClara = MaterialTheme.colorScheme.primary.copy(alpha = .45f)
    val corTrilho = MaterialTheme.colorScheme.surfaceVariant
    val corMeta = CoresOee.atual.alerta
    val corTexto = MaterialTheme.colorScheme.onSurfaceVariant
    val horas = p.porHora
    // Hora atual = a última que já começou.
    val atual = horas.indexOfLast { (paraMs(it.inicioUtc) ?: Long.MAX_VALUE) <= agora }
    val metaHora = p.metaPorMinuto?.let { it * 60 }
    val maximo = maxOf(horas.maxOfOrNull { it.pecas } ?: 0, metaHora?.toInt() ?: 0, 1) * 1.15f
    // Meta até agora (como a Produção por hora do portal): minutos já passados de cada hora × meta por minuto.
    val minutosPassados = horas.map { h ->
        val ini = paraMs(h.inicioUtc) ?: return@map 0.0
        ((agora - ini) / 60_000.0).coerceIn(0.0, 60.0)
    }
    val metaAteAgora = p.metaPorMinuto?.let { m -> m * minutosPassados.sum() }?.takeIf { it > 0 }
    val pctMeta = metaAteAgora?.let { p.pecasTurno / it }
    val cores = CoresOee.atual
    /** ≥ 90% da meta verde, ≥ 50% amarela, abaixo vermelha (mesma regra das barras do portal). */
    fun corDoNivel(pct: Double) = when {
        pct >= 0.9 -> cores.ok
        pct >= 0.5 -> cores.alerta
        else -> cores.erro
    }
    val medidor = androidx.compose.ui.text.rememberTextMeasurer()
    val estiloValor = MaterialTheme.typography.labelSmall.copy(color = MaterialTheme.colorScheme.onSurface)

    Cartao(preenchimento = PaddingValues(16.dp)) {
        Text("Produção do turno", style = MaterialTheme.typography.titleMedium)
        Spacer(Modifier.height(10.dp))
        Row(verticalAlignment = Alignment.Bottom) {
            Column(Modifier.weight(1f)) {
                val textoPcMin = pcMin(p.pecasPorMinuto)
                Text(
                    textoPcMin,
                    // Máquina rápida (milhares de pç/min): letra menor para caber na metade do cartão.
                    style = if (textoPcMin.length > 8) MaterialTheme.typography.headlineSmall else MaterialTheme.typography.headlineMedium,
                    maxLines = 1,
                    softWrap = false,
                    color = if (produzindo) MaterialTheme.colorScheme.primary else corTexto,
                    fontWeight = FontWeight.Bold,
                )
                Text("pç/min agora", style = MaterialTheme.typography.bodyMedium, color = corTexto)
            }
            Column(Modifier.weight(1f), horizontalAlignment = Alignment.End) {
                val textoTurno = numero(p.pecasTurno.toDouble())
                Text(textoTurno, style = if (textoTurno.length > 8) MaterialTheme.typography.headlineSmall else MaterialTheme.typography.headlineMedium,
                    fontWeight = FontWeight.Bold, maxLines = 1, softWrap = false)
                Text("peças no turno", style = MaterialTheme.typography.bodyMedium, color = corTexto)
            }
        }
        Text(
            listOfNotNull(
                // Ciclo com milissegundos (máquinas com mais de uma peça por segundo): o texto do portal; senão o inteiro antigo.
                (p.cicloMedioTexto?.takeIf { it != "—" } ?: p.cicloMedioS?.let { cicloTexto(it) } ?: p.cicloMedio?.let { "$it s" })?.let { "Ciclo $it" },
                "${p.cavidades} ${if (p.cavidades == 1) "cavidade" else "cavidades"}",
                p.metaPorMinuto?.let { "Meta ${pcMin(it)} pç/min" },
            ).joinToString(" · "),
            style = MaterialTheme.typography.labelSmall,
            color = corTexto,
            modifier = Modifier.padding(top = 4.dp),
        )
        if (pctMeta != null) {
            Spacer(Modifier.height(6.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    java.text.NumberFormat.getPercentInstance(Locale("pt", "BR")).format(pctMeta) + " da meta até agora",
                    style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold, color = corDoNivel(pctMeta),
                )
                Spacer(Modifier.width(6.dp))
                Text("(${numero(p.pecasTurno.toDouble())} de ${numero(Math.round(metaAteAgora).toDouble())} pç)",
                    style = MaterialTheme.typography.labelSmall, color = corTexto)
            }
        }
        p.moldeConserto?.let { m ->
            Spacer(Modifier.height(8.dp))
            br.com.alumbra.oee.ui.Aviso(
                "Molde ${m.codigo}${m.descricao?.let { " ($it)" } ?: ""} com ${m.ativas}/${m.total} cavidades e abaixo da meta: indicar para conserto.",
                br.com.alumbra.oee.ui.Situacao.ALERTA,
            )
        }
        Spacer(Modifier.height(12.dp))
        if (horas.isEmpty()) {
            Text("Sem horas do turno para mostrar.", color = corTexto)
            return@Cartao
        }
        Canvas(Modifier.fillMaxWidth().height(150.dp)) {
            val topoLivre = 16.dp.toPx() // espaço para o número em cima da barra
            val alturaUtil = size.height - topoLivre
            val passo = size.width / horas.size
            val largura = (passo * 0.68f).coerceAtLeast(2f)
            horas.forEachIndexed { i, h ->
                val x = i * passo + (passo - largura) / 2
                drawRect(corTrilho, topLeft = Offset(x, topoLivre), size = Size(largura, alturaUtil))
                val alt = (h.pecas / maximo) * alturaUtil
                if (h.pecas > 0) {
                    drawRect(
                        p.metaPorMinuto?.takeIf { minutosPassados[i] > 0 }?.let { m -> corDoNivel(h.pecas / (m * minutosPassados[i])) }
                            ?: if (i == atual) corBarra else if (i > atual && atual >= 0) corBarraClara else corBarra.copy(alpha = .75f),
                        topLeft = Offset(x, topoLivre + alturaUtil - alt),
                        size = Size(largura, alt),
                    )
                    // Número em cima da barra (se couber).
                    val texto = medidor.measure(h.pecas.toString(), estiloValor)
                    if (texto.size.width <= passo) {
                        drawText(texto, topLeft = Offset(x + largura / 2 - texto.size.width / 2, topoLivre + alturaUtil - alt - texto.size.height))
                    }
                }
                if (i == atual) {
                    drawRect(corBarra, topLeft = Offset(x, size.height - 3.dp.toPx()), size = Size(largura, 3.dp.toPx()))
                }
            }
            metaHora?.let { meta ->
                val y = topoLivre + alturaUtil - (meta.toFloat() / maximo) * alturaUtil
                drawLine(corMeta, Offset(0f, y), Offset(size.width, y), strokeWidth = 2.dp.toPx(),
                    pathEffect = androidx.compose.ui.graphics.PathEffect.dashPathEffect(floatArrayOf(12f, 8f)))
            }
        }
        Spacer(Modifier.height(4.dp))
        // Horas embaixo: todas se couberem; senão, uma a cada duas (ou três).
        val pulo = when {
            horas.size <= 10 -> 1
            horas.size <= 18 -> 2
            else -> 3
        }
        Row(Modifier.fillMaxWidth()) {
            horas.forEachIndexed { i, h ->
                Text(
                    if (i % pulo == 0) h.hora.take(2) else "",
                    style = MaterialTheme.typography.labelSmall,
                    color = if (i == atual) MaterialTheme.colorScheme.primary else corTexto,
                    fontWeight = if (i == atual) FontWeight.Bold else FontWeight.Normal,
                    modifier = Modifier.weight(1f),
                    textAlign = androidx.compose.ui.text.style.TextAlign.Center,
                    maxLines = 1,
                )
            }
        }
        if (metaHora != null) {
            Text("— — meta por hora (%s pç)".format(numero(metaHora)), style = MaterialTheme.typography.labelSmall, color = corMeta,
                modifier = Modifier.padding(top = 6.dp))
        }
    }
}

@Composable
private fun CartaoOp(op: OpDetalhe?, podeAlterar: Boolean, aoTrocarOp: () -> Unit, aoDetalhesOp: (OpResumo) -> Unit) {
    Cartao {
        if (op == null) {
            Aviso("Nenhuma ordem de produção nesta máquina.", Situacao.ALERTA)
        } else {
            val r = op.resumo
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("OP ${r.numero}", style = MaterialTheme.typography.titleLarge)
                    Text(
                        listOfNotNull(r.produto, r.descricao).joinToString(" · "),
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                Selo(r.statusRotulo, Situacao.NEUTRA)
            }
            if (r.quantidade > 0) {
                Spacer(Modifier.height(12.dp))
                LinearProgressIndicator(
                    progress = { (r.produzido / r.quantidade).toFloat().coerceIn(0f, 1f) },
                    modifier = Modifier.fillMaxWidth().height(8.dp).clip(MaterialTheme.shapes.small),
                    trackColor = MaterialTheme.colorScheme.surfaceVariant,
                )
                Spacer(Modifier.height(4.dp))
                Text(
                    "Produzido ${numero(r.produzido)} de ${numero(r.quantidade)}",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            if (r.quantidadeReal != null) {
                Spacer(Modifier.height(4.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Contagem IoT", style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.weight(1f))
                    Text("${numero(r.quantidadeReal)} pç", style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.primary)
                }
            }
        }
        if (op != null) {
            Spacer(Modifier.height(14.dp))
            androidx.compose.material3.OutlinedButton(
                onClick = { aoDetalhesOp(op.resumo) },
                modifier = Modifier.fillMaxWidth().height(50.dp),
                shape = MaterialTheme.shapes.medium,
            ) {
                Icon(Icons.Filled.Info, null, Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text("Detalhes da OP")
            }
        }
        if (podeAlterar) {
            Spacer(Modifier.height(if (op != null) 8.dp else 14.dp))
            FilledTonalButton(onClick = aoTrocarOp, modifier = Modifier.fillMaxWidth().height(50.dp), shape = MaterialTheme.shapes.medium) {
                Icon(painterResource(R.drawable.ic_codigo_barras), null, Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text(if (op == null) "Informar OP" else "Trocar OP")
            }
        }
    }
}

@Composable
private fun CartaoMoldes(moldes: List<MoldeApp>, podeAlterar: Boolean, aoAlterar: (MoldeApp) -> Unit) {
    Cartao {
        Text("Molde", style = MaterialTheme.typography.titleMedium)
        if (moldes.isEmpty()) {
            Text("O roteiro desta OP não tem molde (ferramenta).", color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        moldes.forEachIndexed { i, m ->
            if (i > 0) HorizontalDivider(Modifier.padding(vertical = 10.dp), color = CoresOee.atual.borda)
            Spacer(Modifier.height(8.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconeRedondo(vetor = Icons.Filled.Build, tamanho = 44.dp)
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(m.codigo, style = MaterialTheme.typography.titleMedium)
                    Text(m.descricao, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text("${m.cavidadesAtivas}/${m.cavidades}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold,
                        color = MaterialTheme.colorScheme.primary)
                    Text("cavidades", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
            if (podeAlterar) {
                Spacer(Modifier.height(10.dp))
                FilledTonalButton(onClick = { aoAlterar(m) }, modifier = Modifier.fillMaxWidth().height(46.dp), shape = MaterialTheme.shapes.medium) {
                    Text("Alterar cavidades ativas")
                }
            }
        }
    }
}

@Composable
private fun CartaoRoteiro(op: OpDetalhe) {
    Cartao {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Roteiro", style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
            if (op.roteiro != null) Selo("Roteiro ${op.roteiro}", Situacao.NEUTRA)
        }
        if (op.operacoes.isEmpty()) {
            Text("Sem roteiro cadastrado para o produto.", color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        op.operacoes.forEach { o ->
            Spacer(Modifier.height(8.dp))
            Row(
                Modifier.fillMaxWidth().clip(MaterialTheme.shapes.small)
                    .background(if (o.destaMaquina) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surface)
                    .padding(horizontal = 10.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(o.operacao, style = MaterialTheme.typography.titleMedium, modifier = Modifier.width(44.dp))
                Column(Modifier.weight(1f)) {
                    Text(o.descricao.ifBlank { "—" }, style = MaterialTheme.typography.bodyLarge)
                    Text(
                        listOfNotNull(o.maquina.takeIf { it.isNotBlank() }?.let { "Máquina $it" }, o.ferramenta.takeIf { it.isNotBlank() }?.let { "Molde $it" })
                            .joinToString(" · "),
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                if (o.destaMaquina) Selo("Esta máquina", Situacao.OK)
            }
        }
    }
}

@Composable
private fun LinhaApontamento(a: ApontamentoApp) {
    val c = CoresOee.atual
    val cor = corDaLegenda(a.legenda?.cor, MaterialTheme.colorScheme.outline)
    Cartao(preenchimento = PaddingValues(0.dp)) {
        Row(Modifier.height(IntrinsicSize.Min).background(if (a.semMotivo) c.erroSuave else MaterialTheme.colorScheme.surface)) {
            Box(Modifier.width(6.dp).fillMaxHeight().background(cor))
            Column(Modifier.weight(1f).padding(horizontal = 14.dp, vertical = 10.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        a.legenda?.let { "${it.codigo} · ${it.descricao}" } ?: "—",
                        style = MaterialTheme.typography.titleMedium,
                        modifier = Modifier.weight(1f),
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                    if (a.semMotivo) Selo("SEM MOTIVO", Situacao.ERRO)
                }
                Text(
                    "${a.dia ?: ""} ${a.inicio ?: ""} – ${a.fim ?: "agora"} · ${formatarMinutos(a.minutos)}" +
                        (a.pecas?.let { " · $it pç" } ?: "") + (a.op?.let { " · OP $it" } ?: ""),
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}

/** Peças por minuto com 1 casa e milhar ("12.000,0"). */
private fun pcMin(v: Double): String =
    java.text.NumberFormat.getNumberInstance(Locale("pt", "BR")).apply { minimumFractionDigits = 1; maximumFractionDigits = 1 }.format(v)

/** Ciclo em segundos: 3 casas abaixo de 10 s ("0,040 s"), 1 casa acima ("32,0 s"). */
private fun cicloTexto(s: Double): String =
    java.text.NumberFormat.getNumberInstance(Locale("pt", "BR")).apply {
        val casas = if (s < 10) 3 else 1
        minimumFractionDigits = casas; maximumFractionDigits = casas
    }.format(s) + " s"

private fun paraMs(iso: String?): Long? = iso?.let { runCatching { OffsetDateTime.parse(it).toInstant().toEpochMilli() }.getOrNull() }

private fun numero(v: Double): String =
    if (v == Math.floor(v)) "%,.0f".format(Locale("pt", "BR"), v) else "%,.2f".format(Locale("pt", "BR"), v)
