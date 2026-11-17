package br.com.alumbra.oee.os

import android.graphics.Bitmap
import android.graphics.Paint
import android.util.Base64
import androidx.compose.animation.core.Animatable
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectDragGestures
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.IntSize
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import br.com.alumbra.oee.ui.CoresOee
import br.com.alumbra.oee.ui.Selo
import br.com.alumbra.oee.ui.Situacao
import kotlinx.coroutines.launch
import java.io.ByteArrayOutputStream
import kotlin.math.roundToInt

/** Botão deslizante: arrastar até o fim confirma (evita encerrar sem querer). */
@Composable
fun BotaoDeslizante(texto: String, habilitado: Boolean = true, aoConfirmar: () -> Unit) {
    val c = CoresOee.atual
    val escopo = rememberCoroutineScope()
    val deslocamento = remember { Animatable(0f) }
    var largura by remember { mutableIntStateOf(0) }
    val polegar = with(LocalDensity.current) { 64.dp.toPx() }
    val limite = (largura - polegar).coerceAtLeast(1f)

    Box(
        Modifier
            .fillMaxWidth()
            .height(72.dp)
            .clip(RoundedCornerShape(36.dp))
            .background(if (habilitado) c.erro else MaterialTheme.colorScheme.surfaceVariant)
            .onSizeChanged { largura = it.width }
            .pointerInput(habilitado, limite) {
                if (!habilitado) return@pointerInput
                detectHorizontalDragGestures(
                    onDragEnd = {
                        escopo.launch {
                            if (deslocamento.value >= limite * 0.9f) {
                                deslocamento.animateTo(limite)
                                aoConfirmar()
                            }
                            deslocamento.animateTo(0f)
                        }
                    },
                ) { _, delta -> escopo.launch { deslocamento.snapTo((deslocamento.value + delta).coerceIn(0f, limite)) } }
            },
        contentAlignment = Alignment.CenterStart,
    ) {
        Text(
            texto,
            color = c.heroiTexto.copy(alpha = 1f - (deslocamento.value / limite).coerceIn(0f, .8f)),
            style = MaterialTheme.typography.titleMedium,
            modifier = Modifier.align(Alignment.Center).padding(start = 48.dp),
        )
        Box(
            Modifier
                .offset { IntOffset(deslocamento.value.roundToInt(), 0) }
                .padding(4.dp)
                .size(64.dp)
                .clip(CircleShape)
                .background(Color.White),
            contentAlignment = Alignment.Center,
        ) {
            Text("»", fontSize = 30.sp, fontWeight = FontWeight.Bold, color = c.erro)
        }
    }
}

/** Quadro para assinar com o dedo. Guarda os traços; [paraPngBase64] gera a imagem. */
class Assinatura {
    val tracos = mutableStateListOf<List<Offset>>()
    var tamanho by mutableStateOf(IntSize.Zero)
    val vazia: Boolean get() = tracos.none { it.size > 1 }

    fun limpar() = tracos.clear()

    /** PNG (fundo branco, traço preto) em base64. */
    fun paraPngBase64(): String {
        val w = tamanho.width.coerceAtLeast(10)
        val h = tamanho.height.coerceAtLeast(10)
        val bmp = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
        val tela = android.graphics.Canvas(bmp)
        tela.drawColor(android.graphics.Color.WHITE)
        val tinta = Paint().apply {
            color = android.graphics.Color.BLACK
            strokeWidth = 6f
            style = Paint.Style.STROKE
            strokeCap = Paint.Cap.ROUND
            strokeJoin = Paint.Join.ROUND
            isAntiAlias = true
        }
        tracos.forEach { t ->
            val p = android.graphics.Path()
            t.forEachIndexed { i, o -> if (i == 0) p.moveTo(o.x, o.y) else p.lineTo(o.x, o.y) }
            tela.drawPath(p, tinta)
        }
        val saida = ByteArrayOutputStream()
        bmp.compress(Bitmap.CompressFormat.PNG, 100, saida)
        return Base64.encodeToString(saida.toByteArray(), Base64.NO_WRAP)
    }
}

@Composable
fun QuadroAssinatura(assinatura: Assinatura, modifier: Modifier = Modifier) {
    val traco = MaterialTheme.colorScheme.onSurface
    var atual by remember { mutableStateOf<List<Offset>>(emptyList()) }
    Column(modifier) {
        Box(
            Modifier
                .fillMaxWidth()
                .height(180.dp)
                .clip(MaterialTheme.shapes.medium)
                .background(MaterialTheme.colorScheme.surfaceVariant)
                .border(2.dp, MaterialTheme.colorScheme.outline, MaterialTheme.shapes.medium)
                .onSizeChanged { assinatura.tamanho = it }
                .pointerInput(Unit) {
                    detectDragGestures(
                        onDragStart = { atual = listOf(it) },
                        onDragEnd = { assinatura.tracos.add(atual); atual = emptyList() },
                        onDragCancel = { atual = emptyList() },
                    ) { mudanca, _ -> atual = atual + mudanca.position }
                },
        ) {
            Canvas(Modifier.fillMaxSize()) {
                (assinatura.tracos + listOf(atual)).forEach { t ->
                    if (t.size > 1) {
                        val p = Path().apply { t.forEachIndexed { i, o -> if (i == 0) moveTo(o.x, o.y) else lineTo(o.x, o.y) } }
                        drawPath(p, traco, style = Stroke(width = 6f, cap = StrokeCap.Round, join = StrokeJoin.Round))
                    }
                }
            }
            if (assinatura.vazia && atual.isEmpty()) {
                Text("Assine aqui com o dedo", color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.align(Alignment.Center))
            }
            Box(Modifier.align(Alignment.BottomStart).padding(start = 16.dp, end = 16.dp, bottom = 34.dp).fillMaxWidth().height(1.dp)
                .background(MaterialTheme.colorScheme.outline))
        }
        TextButton(onClick = { assinatura.limpar() }, modifier = Modifier.align(Alignment.End)) { Text("Limpar assinatura") }
    }
}

/** Escolha entre poucas opções em botões grandes (ex.: Sim / Não). */
@Composable
fun EscolhaBotoes(opcoes: List<Pair<String, String>>, escolhida: String?, aoEscolher: (String) -> Unit) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        opcoes.forEach { (valor, rotulo) ->
            val marcada = valor == escolhida
            Box(
                Modifier
                    .weight(1f)
                    .height(52.dp)
                    .clip(MaterialTheme.shapes.medium)
                    .background(if (marcada) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surfaceVariant)
                    .clickable { aoEscolher(valor) },
                contentAlignment = Alignment.Center,
            ) {
                Text(rotulo, style = MaterialTheme.typography.labelLarge,
                    color = if (marcada) MaterialTheme.colorScheme.onPrimary else MaterialTheme.colorScheme.onSurface)
            }
        }
    }
}

/** Selo de prioridade/máquina parada da SS ou OS. */
@Composable
fun SelosOs(prioridade: String, maquinaParada: Boolean) {
    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        if (maquinaParada) Selo("Máquina parada", Situacao.ERRO)
        Selo(prioridade, if (prioridade.lowercase() in listOf("urgente", "alta")) Situacao.ALERTA else Situacao.NEUTRA)
    }
}

/** "1 h 05 min". */
fun duracao(minutos: Long): String = if (minutos < 60) "$minutos min" else "${minutos / 60} h ${"%02d".format(minutos % 60)} min"

