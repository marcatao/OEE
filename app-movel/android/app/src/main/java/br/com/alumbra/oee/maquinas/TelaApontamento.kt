package br.com.alumbra.oee.maquinas

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import br.com.alumbra.oee.R
import br.com.alumbra.oee.api.ErroApi
import br.com.alumbra.oee.ui.Aviso
import br.com.alumbra.oee.ui.BarraTopo
import br.com.alumbra.oee.ui.BotaoPrincipal
import br.com.alumbra.oee.ui.Cartao
import br.com.alumbra.oee.ui.CoresOee
import br.com.alumbra.oee.ui.Selo
import br.com.alumbra.oee.ui.Situacao
import br.com.alumbra.oee.ui.corDaLegenda
import kotlinx.coroutines.launch

/**
 * Detalhe de um apontamento real (toque na lista da tela da máquina): todos os dados do apontamento. O operador só
 * pode alterar a ordem de produção dele; ao salvar, volta para a tela da máquina.
 */
@Composable
fun TelaApontamento(
    repositorio: RepositorioMaquinas,
    apontamentoId: Int,
    podeAlterar: Boolean,
    aoVoltar: () -> Unit,
    aoPerderAcesso: (String) -> Unit,
) {
    val escopo = rememberCoroutineScope()
    var detalhe by remember { mutableStateOf<ApontamentoDetalhe?>(null) }
    var erro by remember { mutableStateOf<String?>(null) }
    var escolherOp by remember { mutableStateOf(false) }
    var salvando by remember { mutableStateOf(false) }
    var erroSalvar by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(apontamentoId) {
        try {
            detalhe = repositorio.apontamento(apontamentoId)
        } catch (e: ErroApi) {
            if (e.semAcesso) aoPerderAcesso(e.message ?: "") else erro = e.message
        }
    }

    ConteudoApontamento(detalhe, erro, podeAlterar, aoVoltar, aoAlterarOp = { erroSalvar = null; escolherOp = true })

    val d = detalhe
    if (escolherOp && d != null) {
        DialogoOp(
            repositorio = repositorio,
            obrigatorio = false,
            opAtual = d.base.op,
            ocupado = salvando,
            erro = erroSalvar,
            titulo = "Alterar a OP do apontamento",
            detalheTitulo = "${d.base.dia ?: ""} ${d.base.inicio ?: ""} – ${d.base.fim ?: "agora"} · OP atual: ${d.base.op ?: "nenhuma"}",
            perguntarModo = false,
            aoConfirmar = { numero, _ ->
                salvando = true
                erroSalvar = null
                escopo.launch {
                    try {
                        repositorio.alterarOpDoApontamento(d.base.id, numero)
                        escolherOp = false
                        aoVoltar() // salvo: volta para a tela da máquina (que recarrega)
                    } catch (e: ErroApi) {
                        if (e.semAcesso) aoPerderAcesso(e.message ?: "") else erroSalvar = e.message
                    } finally {
                        salvando = false
                    }
                }
            },
            aoFechar = { if (!salvando) escolherOp = false },
        )
    }
}

@Composable
fun ConteudoApontamento(
    detalhe: ApontamentoDetalhe?,
    erro: String?,
    podeAlterar: Boolean,
    aoVoltar: () -> Unit,
    aoAlterarOp: () -> Unit,
) {
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        BarraTopo(
            titulo = "Apontamento real",
            subtitulo = detalhe?.maquinaNome,
            aoVoltar = aoVoltar,
        )
        if (detalhe == null) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                if (erro != null) Aviso(erro, Situacao.ERRO, Modifier.padding(16.dp)) else CircularProgressIndicator()
            }
            return
        }
        val a = detalhe.base
        Column(
            Modifier.verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding(),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            // Legenda e horário
            Cartao {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(14.dp).clip(CircleShape).background(corDaLegenda(a.legenda?.cor, MaterialTheme.colorScheme.outline)))
                    Spacer(Modifier.width(10.dp))
                    Text(a.legenda?.let { "${it.codigo} · ${it.descricao}" } ?: "—", style = MaterialTheme.typography.titleLarge,
                        modifier = Modifier.weight(1f))
                    when {
                        a.semMotivo -> Selo("SEM MOTIVO", Situacao.ERRO)
                        detalhe.emAberto -> Selo("Em andamento", Situacao.OK)
                    }
                }
                Spacer(Modifier.height(12.dp))
                Campo("Dia", a.dia)
                Campo("Início", a.inicio)
                Campo("Fim", a.fim ?: "em andamento")
                Campo("Duração", formatarDuracao(a.minutos))
                Campo("Tipo", a.legenda?.tipo?.replaceFirstChar { it.uppercase() })
            }

            // Ordem de produção (o único campo que o operador altera)
            Cartao {
                Text("Ordem de produção", style = MaterialTheme.typography.titleMedium)
                Spacer(Modifier.height(8.dp))
                val op = detalhe.op
                if (op == null) {
                    Aviso("Apontamento sem ordem de produção.", Situacao.ALERTA)
                } else {
                    Text("OP ${op.numero}", style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary)
                    Text(listOfNotNull(op.produto, op.descricao).joinToString(" · "), style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Spacer(Modifier.height(6.dp))
                    Selo(op.statusRotulo, Situacao.NEUTRA)
                }
                if (podeAlterar) {
                    Spacer(Modifier.height(14.dp))
                    BotaoPrincipal(texto = if (op == null) "Informar ordem de produção" else "Alterar ordem de produção", aoTocar = aoAlterarOp,
                        icone = R.drawable.ic_codigo_barras)
                }
            }

            // Produção contada pelo IoT
            Cartao {
                Text("Produção (IoT)", style = MaterialTheme.typography.titleMedium)
                Spacer(Modifier.height(8.dp))
                Row {
                    Numero("Peças", a.pecas?.let { "%,d".format(java.util.Locale("pt", "BR"), it) } ?: "—", Modifier.weight(1f), destaque = true)
                    Numero("Ciclos", detalhe.ciclos?.let { "%,d".format(java.util.Locale("pt", "BR"), it) } ?: "—", Modifier.weight(1f))
                }
                Spacer(Modifier.height(10.dp))
                Row {
                    Numero("Cavidades", detalhe.cavidades?.toString() ?: "—", Modifier.weight(1f))
                    Numero("Ciclo médio", detalhe.cicloMedioTexto ?: "—", Modifier.weight(1f))
                }
            }

            // Origem e registro
            Cartao {
                Text("Registro", style = MaterialTheme.typography.titleMedium)
                Spacer(Modifier.height(8.dp))
                Campo("Origem", detalhe.origem)
                Campo("Apontado por", detalhe.criadoPor ?: if (detalhe.origem.startsWith("IoT")) "IoT (automático)" else null)
                Campo("Em", detalhe.criadoEm)
                if (detalhe.atualizadoPor != null) {
                    HorizontalDivider(Modifier.padding(vertical = 8.dp), color = CoresOee.atual.borda)
                    Campo("Alterado por", detalhe.atualizadoPor)
                    Campo("Em", detalhe.atualizadoEm)
                }
                detalhe.referencia?.let { Campo("Referência", it) }
                detalhe.observacao?.let {
                    HorizontalDivider(Modifier.padding(vertical = 8.dp), color = CoresOee.atual.borda)
                    Text("Observação", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Text(it, style = MaterialTheme.typography.bodyMedium)
                }
            }
            if (podeAlterar) {
                Text("Neste aplicativo só a ordem de produção do apontamento pode ser alterada.",
                    style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant,
                    textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth())
            }
        }
    }
}

@Composable
private fun Campo(rotulo: String, valor: String?) {
    Row(Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
        Text(rotulo, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.weight(1f))
        Text(valor ?: "—", style = MaterialTheme.typography.bodyMedium, textAlign = TextAlign.End, modifier = Modifier.weight(1.4f))
    }
}

@Composable
private fun Numero(rotulo: String, valor: String, modifier: Modifier = Modifier, destaque: Boolean = false) {
    Column(modifier) {
        Text(rotulo, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(valor, style = if (destaque) MaterialTheme.typography.titleLarge else MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.SemiBold, color = if (destaque) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface)
    }
}

private fun formatarDuracao(minutos: Double): String {
    val total = Math.round(minutos * 60)
    val h = total / 3600
    val m = (total % 3600) / 60
    val s = total % 60
    return if (h > 0) "%d h %02d min".format(h, m) else if (m > 0) "%d min %02d s".format(m, s) else "%d s".format(s)
}
