package br.com.alumbra.oee.ui

import androidx.annotation.DrawableRes
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import br.com.alumbra.oee.R

/** Situação mostrada nos selos e nas linhas de estado. */
enum class Situacao { OK, ALERTA, ERRO, NEUTRA }

@Composable
fun corDa(situacao: Situacao): Pair<Color, Color> {
    val c = CoresOee.atual
    return when (situacao) {
        Situacao.OK -> c.ok to c.okSuave
        Situacao.ALERTA -> c.alerta to c.alertaSuave
        Situacao.ERRO -> c.erro to c.erroSuave
        Situacao.NEUTRA -> MaterialTheme.colorScheme.onSurfaceVariant to MaterialTheme.colorScheme.surfaceVariant
    }
}

/** Fundo verde em degradê do topo das telas (o "herói" do portal). */
@Composable
fun FundoHeroi(modifier: Modifier = Modifier, conteudo: @Composable ColumnScope.() -> Unit) {
    val c = CoresOee.atual
    Column(
        modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(bottomStart = 28.dp, bottomEnd = 28.dp))
            .background(Brush.linearGradient(listOf(c.heroiInicio, c.heroiFim))),
        content = conteudo,
    )
}

/** Logo "A" da Alumbra num quadrado translúcido. */
@Composable
fun LogoAlumbra(tamanho: Dp) {
    Box(
        Modifier
            .size(tamanho)
            .clip(RoundedCornerShape(tamanho * 0.26f))
            .background(Color.White.copy(alpha = .16f))
            .border(1.dp, Color.White.copy(alpha = .25f), RoundedCornerShape(tamanho * 0.26f)),
        contentAlignment = Alignment.Center,
    ) {
        Image(painterResource(R.drawable.logo_a), contentDescription = null, Modifier.size(tamanho * 1.1f))
    }
}

/** Cartão branco com borda suave e cantos arredondados. */
@Composable
fun Cartao(
    modifier: Modifier = Modifier,
    preenchimento: PaddingValues = PaddingValues(20.dp),
    conteudo: @Composable ColumnScope.() -> Unit,
) {
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = MaterialTheme.shapes.large,
        color = MaterialTheme.colorScheme.surface,
        shadowElevation = 2.dp,
        border = androidx.compose.foundation.BorderStroke(1.dp, CoresOee.atual.borda),
    ) {
        Column(Modifier.padding(preenchimento), content = conteudo)
    }
}

/** Selo em pílula: ponto colorido + texto. */
@Composable
fun Selo(texto: String, situacao: Situacao, modifier: Modifier = Modifier) {
    val (cor, fundo) = corDa(situacao)
    Row(
        modifier.clip(CircleShape).background(fundo).padding(horizontal = 10.dp, vertical = 5.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(7.dp).clip(CircleShape).background(cor))
        Spacer(Modifier.width(6.dp))
        Text(texto, style = MaterialTheme.typography.labelSmall, color = cor, maxLines = 1)
    }
}

/** Ícone dentro de um círculo colorido. */
@Composable
fun IconeRedondo(
    @DrawableRes recurso: Int? = null,
    vetor: ImageVector? = null,
    situacao: Situacao = Situacao.OK,
    tamanho: Dp = 44.dp,
) {
    val (cor, fundo) = when (situacao) {
        Situacao.OK -> MaterialTheme.colorScheme.primary to MaterialTheme.colorScheme.primaryContainer
        else -> corDa(situacao)
    }
    Box(Modifier.size(tamanho).clip(CircleShape).background(fundo), contentAlignment = Alignment.Center) {
        val mod = Modifier.size(tamanho * 0.52f)
        if (recurso != null) Icon(painterResource(recurso), null, mod, tint = cor)
        if (vetor != null) Icon(vetor, null, mod, tint = cor)
    }
}

/** Linha de estado: ícone, título, detalhe e um selo à direita. */
@Composable
fun LinhaEstado(
    @DrawableRes icone: Int,
    titulo: String,
    detalhe: String,
    situacao: Situacao,
    selo: String,
    carregando: Boolean = false,
) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        IconeRedondo(recurso = icone, situacao = situacao, tamanho = 40.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    titulo,
                    style = MaterialTheme.typography.titleMedium,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f),
                )
                Spacer(Modifier.width(8.dp))
                if (carregando) {
                    CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                } else {
                    Selo(selo, situacao)
                }
            }
            Spacer(Modifier.height(2.dp))
            Text(detalhe, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

/** Botão principal grande (56 dp, bom para usar com luva no chão de fábrica). */
@Composable
fun BotaoPrincipal(
    texto: String,
    aoTocar: () -> Unit,
    modifier: Modifier = Modifier,
    habilitado: Boolean = true,
    carregando: Boolean = false,
    @DrawableRes icone: Int? = null,
    vetor: ImageVector? = null,
) {
    Button(
        onClick = aoTocar,
        enabled = habilitado && !carregando,
        modifier = modifier.fillMaxWidth().height(56.dp),
        shape = MaterialTheme.shapes.medium,
        colors = ButtonDefaults.buttonColors(),
    ) {
        if (carregando) {
            CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp, color = MaterialTheme.colorScheme.onPrimary)
        } else {
            if (icone != null) Icon(painterResource(icone), null, Modifier.size(22.dp))
            if (vetor != null) Icon(vetor, null, Modifier.size(22.dp))
            if (icone != null || vetor != null) Spacer(Modifier.width(10.dp))
            Text(texto, style = MaterialTheme.typography.labelLarge)
        }
    }
}

/** Caixa de aviso colorida (erro, alerta ou informação). */
@Composable
fun Aviso(texto: String, situacao: Situacao, modifier: Modifier = Modifier, titulo: String? = null) {
    val (cor, fundo) = corDa(situacao)
    Row(
        modifier.fillMaxWidth().clip(MaterialTheme.shapes.medium).background(fundo).padding(14.dp),
        verticalAlignment = Alignment.Top,
    ) {
        Box(Modifier.padding(top = 6.dp).size(8.dp).clip(CircleShape).background(cor))
        Spacer(Modifier.width(10.dp))
        Column {
            if (titulo != null) Text(titulo, style = MaterialTheme.typography.titleMedium, color = cor)
            Text(texto, style = MaterialTheme.typography.bodyMedium, color = if (titulo != null) MaterialTheme.colorScheme.onSurface else cor)
        }
    }
}

/** Atalho de função do app: ícone, título, descrição, selo e seta. */
@Composable
fun AtalhoFuncao(
    @DrawableRes icone: Int? = null,
    vetor: ImageVector? = null,
    titulo: String,
    descricao: String,
    selo: String?,
    habilitado: Boolean,
    aoTocar: () -> Unit,
) {
    Cartao(
        Modifier
            .alpha(if (habilitado) 1f else .55f)
            .clip(MaterialTheme.shapes.large)
            .clickable(enabled = habilitado, onClick = aoTocar),
        preenchimento = PaddingValues(16.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconeRedondo(recurso = icone, vetor = vetor, tamanho = 52.dp)
            Spacer(Modifier.width(14.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(titulo, style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f, fill = false))
                    if (selo != null) {
                        Spacer(Modifier.width(8.dp))
                        Selo(selo, Situacao.NEUTRA)
                    }
                }
                Text(descricao, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Spacer(Modifier.width(8.dp))
            Text("›", style = MaterialTheme.typography.headlineMedium, color = MaterialTheme.colorScheme.outline)
        }
    }
}

/** Título de seção, em caixa alta e discreto. */
@Composable
fun TituloSecao(texto: String, modifier: Modifier = Modifier) {
    Text(
        texto.uppercase(),
        style = MaterialTheme.typography.labelSmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = modifier.padding(start = 4.dp, top = 8.dp, bottom = 2.dp),
    )
}
