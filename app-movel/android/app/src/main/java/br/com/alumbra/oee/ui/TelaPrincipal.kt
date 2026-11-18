package br.com.alumbra.oee.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ExitToApp
import androidx.compose.material.icons.filled.Build
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import br.com.alumbra.oee.BuildConfig
import br.com.alumbra.oee.R
import br.com.alumbra.oee.sessao.UsuarioApp
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/** Tela do usuário logado: quem é, situação da sincronização e as funções que as permissões do portal liberam. */
@Composable
fun TelaPrincipal(
    usuario: UsuarioApp,
    ultimaSincronizacao: Long,
    horasOffline: Int,
    bloqueada: Boolean,
    sincronizando: Boolean,
    mensagem: String?,
    naRedeDaFabrica: Boolean,
    aoSincronizar: () -> Unit,
    aoSair: () -> Unit,
    aoAbrirFuncao: (String) -> Unit = {},
    pendenciasOs: Int = 0,
    itensAlerta: List<br.com.alumbra.oee.alertas.ItemAlerta>? = null,
    aoAjustarAlerta: (android.content.Intent) -> Unit = {},
    aoTestarAlerta: () -> Unit = {},
    agora: Long = System.currentTimeMillis(),
) {
    var confirmarSaida by remember { mutableStateOf(false) }

    Column(
        Modifier
            .fillMaxSize()
            .background(MaterialTheme.colorScheme.background)
            .verticalScroll(rememberScrollState()),
    ) {
        Cabecalho(usuario, aoSair = { confirmarSaida = true })

        Column(
            Modifier
                .offset(y = (-36).dp)
                .padding(horizontal = 16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            CartaoSincronizacao(ultimaSincronizacao, horasOffline, bloqueada, sincronizando, mensagem, naRedeDaFabrica, aoSincronizar, agora)

            if (itensAlerta != null) CartaoAlertas(itensAlerta, aoAjustarAlerta, aoTestarAlerta)

            TituloSecao("Funções")
            val temFuncao = usuario.operador || usuario.timeline || usuario.mantenedor || usuario.aprovarOs || usuario.solicitarOs
            if (usuario.operador || usuario.timeline) {
                AtalhoFuncao(
                    icone = R.drawable.ic_maquina,
                    titulo = "Máquinas",
                    descricao = "Apontamentos da máquina, motivo das paradas, OP, roteiro e cavidades do molde.",
                    selo = if (bloqueada) "Bloqueado" else null,
                    habilitado = !bloqueada,
                    aoTocar = { aoAbrirFuncao("maquinas") },
                )
            }
            if (usuario.mantenedor || usuario.aprovarOs || usuario.solicitarOs) {
                AtalhoFuncao(
                    vetor = Icons.Filled.Build,
                    titulo = "Ordens de serviço",
                    descricao = "Assumir e abrir SS, minhas OS, apontamento com cronômetro e avaliação com assinatura.",
                    selo = if (bloqueada) "Bloqueado" else if (pendenciasOs > 0) "$pendenciasOs pendente(s)" else null,
                    habilitado = !bloqueada,
                    aoTocar = { aoAbrirFuncao("ordens_servico") },
                )
            }
            if (!temFuncao) {
                Aviso(
                    titulo = "Nenhuma função liberada",
                    texto = "Seu acesso não inclui funções do aplicativo. Peça a um administrador do portal.",
                    situacao = Situacao.NEUTRA,
                )
            }

            Spacer(Modifier.height(8.dp))
            OutlinedButton(
                onClick = { confirmarSaida = true },
                modifier = Modifier.fillMaxWidth().height(52.dp),
                shape = MaterialTheme.shapes.medium,
            ) {
                Icon(Icons.AutoMirrored.Filled.ExitToApp, null, Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text("Sair e trocar de usuário")
            }
            Text(
                "OEE Alumbra ${BuildConfig.VERSION_NAME} · build ${BuildConfig.VERSION_CODE}",
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.fillMaxWidth().navigationBarsPadding(),
                textAlign = TextAlign.Center,
            )
        }
    }

    if (confirmarSaida) {
        AlertDialog(
            onDismissRequest = { confirmarSaida = false },
            icon = { Icon(Icons.AutoMirrored.Filled.ExitToApp, null) },
            title = { Text("Sair do aplicativo?") },
            text = { Text("Para entrar de novo será preciso estar na rede ${BuildConfig.REDE_WIFI}.") },
            confirmButton = { TextButton(onClick = { confirmarSaida = false; aoSair() }) { Text("Sair") } },
            dismissButton = { TextButton(onClick = { confirmarSaida = false }) { Text("Cancelar") } },
        )
    }
}

/** Situação dos alertas com a tela apagada e o app fechado: o que falta liberar e um teste. */
@Composable
private fun CartaoAlertas(
    itens: List<br.com.alumbra.oee.alertas.ItemAlerta>,
    aoAjustar: (android.content.Intent) -> Unit,
    aoTestar: () -> Unit,
) {
    val tudoOk = itens.all { it.ok }
    Cartao {
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconeRedondo(vetor = Icons.Filled.Lock, situacao = if (tudoOk) Situacao.OK else Situacao.ERRO, tamanho = 44.dp)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text("Alarme com a tela apagada", style = MaterialTheme.typography.titleMedium)
                Text(
                    if (tudoOk) "Liberado: o celular toca mesmo com o app fechado." else "Falta liberar no celular: toque em cada item.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
        Spacer(Modifier.height(10.dp))
        itens.forEach { item ->
            Row(
                Modifier.fillMaxWidth().clip(MaterialTheme.shapes.small)
                    .clickable(enabled = !item.ok && item.ajuste != null) { item.ajuste?.let(aoAjustar) }
                    .padding(vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(item.titulo, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
                Selo(if (item.ok) "OK" else "Liberar", if (item.ok) Situacao.OK else Situacao.ERRO)
            }
        }
        Spacer(Modifier.height(8.dp))
        OutlinedButton(onClick = aoTestar, modifier = Modifier.fillMaxWidth().height(48.dp), shape = MaterialTheme.shapes.medium) {
            Text("Testar com a tela apagada (toca em 15 s)")
        }
        Text(
            "Em Xiaomi, Samsung e Motorola, libere também \"Início automático\" / \"Sem restrições\" do app na bateria.",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.padding(top = 8.dp),
        )
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun Cabecalho(usuario: UsuarioApp, aoSair: () -> Unit) {
    val c = CoresOee.atual
    FundoHeroi {
        Column(
            Modifier
                .fillMaxWidth()
                .statusBarsPadding()
                .padding(start = 20.dp, end = 8.dp, top = 20.dp, bottom = 60.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(
                    Modifier
                        .size(56.dp)
                        .clip(CircleShape)
                        .background(Color.White.copy(alpha = .18f))
                        .border(2.dp, Color.White.copy(alpha = .35f), CircleShape),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(iniciais(usuario.nome), style = MaterialTheme.typography.titleLarge, color = c.heroiTexto)
                }
                Spacer(Modifier.width(14.dp))
                Column(Modifier.weight(1f)) {
                    Text("Olá, ${usuario.primeiroNome}", style = MaterialTheme.typography.headlineMedium, color = c.heroiTexto,
                        maxLines = 1, overflow = TextOverflow.Ellipsis)
                    if (usuario.email.isNotBlank()) {
                        Text(usuario.email, style = MaterialTheme.typography.bodyMedium, color = c.heroiSuave,
                            maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
                IconButton(onClick = aoSair) {
                    Icon(Icons.AutoMirrored.Filled.ExitToApp, contentDescription = "Sair", tint = c.heroiTexto)
                }
            }
            if (usuario.perfis.isNotEmpty()) {
                Spacer(Modifier.height(16.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    usuario.perfis.forEach { perfil ->
                        Text(
                            perfil,
                            style = MaterialTheme.typography.labelMedium,
                            color = c.heroiTexto,
                            modifier = Modifier
                                .clip(CircleShape)
                                .background(Color.White.copy(alpha = .16f))
                                .padding(horizontal = 12.dp, vertical = 6.dp),
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun CartaoSincronizacao(
    ultima: Long,
    horasOffline: Int,
    bloqueada: Boolean,
    sincronizando: Boolean,
    mensagem: String?,
    naRedeDaFabrica: Boolean,
    aoSincronizar: () -> Unit,
    agora: Long,
) {
    Cartao {
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconeRedondo(
                vetor = if (bloqueada) Icons.Filled.Lock else Icons.Filled.Refresh,
                situacao = if (bloqueada) Situacao.ERRO else Situacao.OK,
                tamanho = 48.dp,
            )
            Spacer(Modifier.width(14.dp))
            Column(Modifier.weight(1f)) {
                Text(if (bloqueada) "Sincronização necessária" else "Dados sincronizados", style = MaterialTheme.typography.titleMedium)
                Text(
                    if (ultima > 0) "Última: ${tempoDesde(ultima, agora)}" else "Ainda não sincronizado",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
        Spacer(Modifier.height(14.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Rede", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Spacer(Modifier.weight(1f))
            if (naRedeDaFabrica) {
                Selo(BuildConfig.REDE_WIFI, Situacao.OK)
            } else {
                Selo("Fora da rede da fábrica", Situacao.ALERTA)
            }
        }
        Spacer(Modifier.height(14.dp))
        FilledTonalButton(
            onClick = aoSincronizar,
            enabled = naRedeDaFabrica && !sincronizando,
            shape = MaterialTheme.shapes.medium,
            modifier = Modifier.fillMaxWidth().height(48.dp),
        ) {
            Icon(Icons.Filled.Refresh, null, Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
            Text(if (sincronizando) "Sincronizando…" else "Sincronizar agora")
        }
        if (bloqueada) {
            Spacer(Modifier.height(14.dp))
            Aviso(
                titulo = "Aplicativo bloqueado",
                texto = "Passaram mais de $horasOffline h sem sincronizar. Conecte à rede ${BuildConfig.REDE_WIFI} e toque em Sincronizar.",
                situacao = Situacao.ERRO,
            )
        } else if (!naRedeDaFabrica) {
            Spacer(Modifier.height(10.dp))
            Text(
                "Sem a rede da fábrica o app trabalha com os dados guardados no celular.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        if (mensagem != null) {
            Spacer(Modifier.height(12.dp))
            Aviso(texto = mensagem, situacao = Situacao.ERRO)
        }
    }
}

private fun iniciais(nome: String): String =
    nome.trim().split(Regex("\\s+")).filter { it.isNotEmpty() }.let { partes ->
        when {
            partes.isEmpty() -> "?"
            partes.size == 1 -> partes[0].take(2)
            else -> "${partes.first().first()}${partes.last().first()}"
        }
    }.uppercase()

/** "agora", "há 5 min", "há 3 h" ou a data, com a hora. */
private fun tempoDesde(momento: Long, agora: Long): String {
    val minutos = (agora - momento) / 60_000
    val hora = SimpleDateFormat("dd/MM HH:mm", Locale("pt", "BR")).format(Date(momento))
    return when {
        minutos < 1 -> "agora há pouco"
        minutos < 60 -> "há $minutos min ($hora)"
        minutos < 48 * 60 -> "há ${minutos / 60} h ($hora)"
        else -> hora
    }
}
