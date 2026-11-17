package br.com.alumbra.oee.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.Immutable
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/*
 * Cores do portal (variáveis do topo de public/css/portal.css, tema claro e escuro). As telas usam só
 * MaterialTheme.colorScheme e CoresOee.atual — nunca cores soltas.
 */

/** Cores de situação (ok, alerta, erro) e da faixa do topo, que o Material 3 não tem. */
@Immutable
data class CoresExtras(
    val heroiInicio: Color,
    val heroiFim: Color,
    val heroiTexto: Color,
    val heroiSuave: Color,
    val ok: Color,
    val okSuave: Color,
    val alerta: Color,
    val alertaSuave: Color,
    val erro: Color,
    val erroSuave: Color,
    val borda: Color,
    val fundoEscurecido: Color,
)

private val ExtrasClaro = CoresExtras(
    heroiInicio = Color(0xFF00663D), heroiFim = Color(0xFF0A8F58),
    heroiTexto = Color(0xFFFFFFFF), heroiSuave = Color(0xCCFFFFFF),
    ok = Color(0xFF067647), okSuave = Color(0xFFE3F6EC),
    alerta = Color(0xFFB54708), alertaSuave = Color(0xFFFEF3E2),
    erro = Color(0xFFB42318), erroSuave = Color(0xFFFDECEA),
    borda = Color(0xFFDDE6E0),
    fundoEscurecido = Color(0x73000000), // --fundo-escurecido do portal
)

private val ExtrasEscuro = CoresExtras(
    heroiInicio = Color(0xFF0B3D27), heroiFim = Color(0xFF106B43),
    heroiTexto = Color(0xFFFFFFFF), heroiSuave = Color(0xCCFFFFFF),
    ok = Color(0xFF47CD89), okSuave = Color(0xFF12301F),
    alerta = Color(0xFFFDB022), alertaSuave = Color(0xFF33240B),
    erro = Color(0xFFF97066), erroSuave = Color(0xFF3A1714),
    borda = Color(0xFF243229),
    fundoEscurecido = Color(0x99000000),
)

private val LocalCoresExtras = staticCompositionLocalOf { ExtrasClaro }

object CoresOee {
    val atual: CoresExtras @Composable get() = LocalCoresExtras.current
}

private val Claro = lightColorScheme(
    primary = Color(0xFF008650),
    onPrimary = Color(0xFFFFFFFF),
    primaryContainer = Color(0xFFE4F3EB),
    onPrimaryContainer = Color(0xFF006A3F),
    secondary = Color(0xFF3FB37A),
    secondaryContainer = Color(0xFFE4F3EB),
    onSecondaryContainer = Color(0xFF006A3F),
    background = Color(0xFFF2F5F3),
    onBackground = Color(0xFF16231C),
    surface = Color(0xFFFFFFFF),
    onSurface = Color(0xFF16231C),
    surfaceVariant = Color(0xFFEEF2EF),
    onSurfaceVariant = Color(0xFF5B6B62),
    outline = Color(0xFFC6D4CB),
    outlineVariant = Color(0xFFDDE6E0),
    error = Color(0xFFB42318),
    errorContainer = Color(0xFFFDECEA),
)

private val Escuro = darkColorScheme(
    primary = Color(0xFF3FBF82),
    onPrimary = Color(0xFF07150E),
    primaryContainer = Color(0xFF163828),
    onPrimaryContainer = Color(0xFF4CC38A),
    secondary = Color(0xFF4CC38A),
    secondaryContainer = Color(0xFF163828),
    onSecondaryContainer = Color(0xFF4CC38A),
    background = Color(0xFF0E1511),
    onBackground = Color(0xFFE4EDE7),
    surface = Color(0xFF151F19),
    onSurface = Color(0xFFE4EDE7),
    surfaceVariant = Color(0xFF1C2721),
    onSurfaceVariant = Color(0xFF9DB0A5),
    outline = Color(0xFF2F4136),
    outlineVariant = Color(0xFF243229),
    error = Color(0xFFF97066),
    errorContainer = Color(0xFF3A1714),
)

private val Tipografia = Typography(
    headlineMedium = TextStyle(fontSize = 26.sp, lineHeight = 32.sp, fontWeight = FontWeight.Bold),
    titleLarge = TextStyle(fontSize = 20.sp, lineHeight = 26.sp, fontWeight = FontWeight.SemiBold),
    titleMedium = TextStyle(fontSize = 17.sp, lineHeight = 22.sp, fontWeight = FontWeight.SemiBold),
    bodyLarge = TextStyle(fontSize = 16.sp, lineHeight = 22.sp),
    bodyMedium = TextStyle(fontSize = 14.sp, lineHeight = 20.sp),
    labelLarge = TextStyle(fontSize = 16.sp, lineHeight = 20.sp, fontWeight = FontWeight.SemiBold),
    labelMedium = TextStyle(fontSize = 13.sp, lineHeight = 16.sp, fontWeight = FontWeight.Medium),
    labelSmall = TextStyle(fontSize = 12.sp, lineHeight = 16.sp, fontWeight = FontWeight.Medium, letterSpacing = 0.4.sp),
)

private val Formas = Shapes(
    small = RoundedCornerShape(10.dp),
    medium = RoundedCornerShape(16.dp),
    large = RoundedCornerShape(22.dp),
)

@Composable
fun TemaOee(escuro: Boolean = isSystemInDarkTheme(), conteudo: @Composable () -> Unit) {
    CompositionLocalProvider(LocalCoresExtras provides if (escuro) ExtrasEscuro else ExtrasClaro) {
        MaterialTheme(
            colorScheme = if (escuro) Escuro else Claro,
            typography = Tipografia,
            shapes = Formas,
            content = conteudo,
        )
    }
}
