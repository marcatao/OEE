package br.com.alumbra.oee.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

/** Cores do portal (variáveis do topo de public/css/portal.css). Não use cores soltas nas telas. */
object Cores {
    val heroiInicio = Color(0xFF00663D)
    val heroiFim = Color(0xFF0A8F58)
    val alertaParada = Color(0xFFB42318)
}

private val Claro = lightColorScheme(
    primary = Color(0xFF008650),
    onPrimary = Color(0xFFFFFFFF),
    primaryContainer = Color(0xFFE4F3EB),
    onPrimaryContainer = Color(0xFF006A3F),
    secondary = Color(0xFF3FB37A),
    background = Color(0xFFF2F5F3),
    onBackground = Color(0xFF16231C),
    surface = Color(0xFFFFFFFF),
    onSurface = Color(0xFF16231C),
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
    background = Color(0xFF0E1511),
    onBackground = Color(0xFFE4EDE7),
    surface = Color(0xFF151F19),
    onSurface = Color(0xFFE4EDE7),
    onSurfaceVariant = Color(0xFF9DB0A5),
    outline = Color(0xFF2F4136),
    outlineVariant = Color(0xFF243229),
    error = Color(0xFFF97066),
    errorContainer = Color(0xFF3A1714),
)

@Composable
fun TemaOee(conteudo: @Composable () -> Unit) {
    MaterialTheme(colorScheme = if (isSystemInDarkTheme()) Escuro else Claro, content = conteudo)
}
