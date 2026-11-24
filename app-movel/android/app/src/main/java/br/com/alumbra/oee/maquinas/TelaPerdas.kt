package br.com.alumbra.oee.maquinas

import androidx.compose.foundation.background
import androidx.compose.material.icons.filled.Refresh
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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import br.com.alumbra.oee.api.ErroApi
import br.com.alumbra.oee.ui.Aviso
import br.com.alumbra.oee.ui.BarraTopo
import br.com.alumbra.oee.ui.BotaoPrincipal
import br.com.alumbra.oee.ui.Cartao
import br.com.alumbra.oee.ui.CoresOee
import br.com.alumbra.oee.ui.Selo
import br.com.alumbra.oee.ui.Situacao
import br.com.alumbra.oee.ui.TituloSecao
import kotlinx.coroutines.launch
import java.text.NumberFormat
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/** Campos do formulário de perda (o que foi digitado e qual campo o operador preencheu por último). */
data class FormPerda(
    val quantidade: String = "",
    val peso: String = "",
    /** "quantidade" ou "peso": o campo digitado; o outro é calculado pelo PU. */
    val digitado: String = "quantidade",
    /** 0 = hoje, 1 = ontem, 2 = anteontem (nunca no futuro). */
    val diasAtras: Int = 0,
    val observacao: String = "",
)

/**
 * Apontamento de perdas da OP (botão na tela da máquina): calculadora pelo PU (quantidade ⇄ peso), confirmação antes
 * de gravar e de excluir, e a lista da OP com as excluídas riscadas ("Excluído por Fulano"). Grava só com rede (a
 * mesma regra do portal, App\Services\PerdasOrdemProducao).
 */
@Composable
fun TelaPerdas(
    repositorio: RepositorioMaquinas,
    opId: Int,
    numero: String,
    maquinaId: Int,
    maquinaNome: String,
    aoVoltar: () -> Unit,
    aoPerderAcesso: (String) -> Unit,
) {
    val escopo = rememberCoroutineScope()
    var dados by remember { mutableStateOf<PerdasOp?>(null) }
    var erro by remember { mutableStateOf<String?>(null) }
    var form by remember { mutableStateOf(FormPerda()) }
    var confirmarGravar by remember { mutableStateOf(false) }
    var excluir by remember { mutableStateOf<PerdaApp?>(null) }
    var ocupado by remember { mutableStateOf(false) }
    var erroAcao by remember { mutableStateOf<String?>(null) }
    var aviso by remember { mutableStateOf<String?>(null) }
    var recarregar by remember { mutableIntStateOf(0) }

    LaunchedEffect(opId, recarregar) {
        try {
            dados = repositorio.perdas(opId)
            erro = null
        } catch (e: ErroApi) {
            if (e.semAcesso) aoPerderAcesso(e.message ?: "") else erro = e.message
        }
    }

    fun executar(acao: suspend () -> PerdasOp, depois: () -> Unit) {
        ocupado = true
        erroAcao = null
        escopo.launch {
            try {
                dados = acao()
                depois()
            } catch (e: ErroApi) {
                if (e.semAcesso) aoPerderAcesso(e.message ?: "") else erroAcao = e.message
            } finally {
                ocupado = false
            }
        }
    }

    ConteudoPerdas(
        numero = numero,
        maquinaNome = maquinaNome,
        dados = dados,
        erro = erro,
        form = form,
        aoMudarForm = { form = it; erroAcao = null; aviso = null },
        erroAcao = if (confirmarGravar || excluir != null) null else erroAcao,
        aviso = aviso,
        aoApontar = { confirmarGravar = true },
        aoExcluir = { excluir = it; erroAcao = null },
        aoVoltar = aoVoltar,
        aoAtualizar = { recarregar++ },
    )

    val d = dados
    if (confirmarGravar && d != null) {
        val calc = calcular(form, d.pesoUnitario)
        Confirmacao(
            titulo = "Confirmar a perda",
            texto = "Apontar ${textoPerda(calc.first, calc.second)} de perda na OP ${d.numero}, " +
                "dia ${diaTexto(form.diasAtras)}, máquina $maquinaNome?",
            botao = "Sim, apontar a perda",
            situacao = Situacao.ALERTA,
            ocupado = ocupado,
            erro = erroAcao,
            aoConfirmar = {
                val q = if (form.digitado == "quantidade" || d.pesoUnitario == null) numeroDe(form.quantidade) else null
                val p = if (q == null) numeroDe(form.peso) else null
                executar({ repositorio.apontarPerda(d.opId, q, p, diaIso(form.diasAtras), maquinaId, form.observacao) }) {
                    confirmarGravar = false
                    form = FormPerda()
                    aviso = "Perda apontada."
                }
            },
            aoCancelar = { if (!ocupado) confirmarGravar = false },
        )
    }
    excluir?.let { perda ->
        Confirmacao(
            titulo = "Excluir esta perda?",
            texto = "${perda.texto} do dia ${dataCurta(perda.dia)}. Ela sai do total da OP e continua na lista como " +
                "\"Excluído por você\".",
            botao = "Sim, excluir",
            situacao = Situacao.ERRO,
            ocupado = ocupado,
            erro = erroAcao,
            aoConfirmar = { executar({ repositorio.excluirPerda(perda.id) }) { excluir = null; aviso = "Perda excluída." } },
            aoCancelar = { if (!ocupado) excluir = null },
        )
    }
}

@Composable
fun ConteudoPerdas(
    numero: String,
    maquinaNome: String,
    dados: PerdasOp?,
    erro: String?,
    form: FormPerda,
    aoMudarForm: (FormPerda) -> Unit,
    erroAcao: String?,
    aviso: String?,
    aoApontar: () -> Unit,
    aoExcluir: (PerdaApp) -> Unit,
    aoVoltar: () -> Unit,
    aoAtualizar: () -> Unit,
) {
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        BarraTopo(titulo = "Perdas da OP $numero", subtitulo = maquinaNome, aoVoltar = aoVoltar) {
            androidx.compose.material3.IconButton(onClick = aoAtualizar) {
                androidx.compose.material3.Icon(androidx.compose.material.icons.Icons.Filled.Refresh, "Atualizar", tint = CoresOee.atual.heroiTexto)
            }
        }
        if (dados == null) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                if (erro != null) Aviso(erro, Situacao.ERRO, Modifier.padding(16.dp)) else CircularProgressIndicator()
            }
            return
        }
        val pu = dados.pesoUnitario
        Column(
            Modifier.verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding(),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            // Resumo: total da OP e PU.
            Cartao {
                Row(verticalAlignment = Alignment.Bottom) {
                    Column(Modifier.weight(1f)) {
                        Text("Perdas da OP", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Text("${qtd(dados.totalQuantidade)} pç", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold,
                            color = if (dados.totalQuantidade > 0) CoresOee.atual.erro else MaterialTheme.colorScheme.onSurface)
                        Text(
                            listOfNotNull(
                                if (dados.totalPeso > 0) "${qtd(dados.totalPeso)} kg" else null,
                                if (dados.quantidadeOp > 0) pct(dados.totalQuantidade / dados.quantidadeOp) + " da OP (${qtd(dados.quantidadeOp)} pç)" else null,
                            ).joinToString(" · "),
                            style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                    Column(horizontalAlignment = Alignment.End) {
                        Text("PU (peso de 1 peça)", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Text(pu?.let { "${qtd(it, 6)} kg" } ?: "sem PU", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                    }
                }
                Text("${dados.apontamentos} ${if (dados.apontamentos == 1) "apontamento" else "apontamentos"} · produto ${dados.produto}",
                    style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(top = 6.dp))
            }

            // Nova perda: calculadora pelo PU.
            Cartao {
                Text("Apontar perda", style = MaterialTheme.typography.titleMedium)
                Spacer(Modifier.height(10.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    OutlinedTextField(
                        value = form.quantidade,
                        onValueChange = { t ->
                            val limpo = limparNumero(t)
                            aoMudarForm(form.copy(quantidade = limpo, digitado = "quantidade",
                                peso = if (pu != null) numeroDe(limpo)?.let { qtd(it * pu, 4) } ?: "" else ""))
                        },
                        label = { Text("Quantidade (pç)") },
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        singleLine = true,
                        textStyle = MaterialTheme.typography.headlineSmall,
                        modifier = Modifier.weight(1f),
                        shape = MaterialTheme.shapes.medium,
                    )
                    OutlinedTextField(
                        value = form.peso,
                        onValueChange = { t ->
                            val limpo = limparNumero(t)
                            aoMudarForm(form.copy(peso = limpo, digitado = "peso",
                                quantidade = if (pu != null) numeroDe(limpo)?.let { qtd(it / pu, 2) } ?: "" else form.quantidade))
                        },
                        label = { Text("Peso (kg)") },
                        enabled = pu != null,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        singleLine = true,
                        textStyle = MaterialTheme.typography.headlineSmall,
                        modifier = Modifier.weight(1f),
                        shape = MaterialTheme.shapes.medium,
                    )
                }
                Spacer(Modifier.height(6.dp))
                val calc = calcular(form, pu)
                when {
                    pu == null -> Aviso("Produto sem PU (peso unitário) no Protheus: informe só a quantidade de peças.", Situacao.ALERTA)
                    calc.first != null && calc.second != null -> Text(
                        if (form.digitado == "peso") "${qtd(calc.second!!, 4)} kg ÷ ${qtd(pu, 6)} kg = ${qtd(calc.first!!, 2)} pç"
                        else "${qtd(calc.first!!, 2)} pç × ${qtd(pu, 6)} kg = ${qtd(calc.second!!, 4)} kg",
                        style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.primary, fontWeight = FontWeight.SemiBold,
                    )
                    else -> Text("Digite a quantidade OU o peso: o outro é calculado pelo PU.", style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                Spacer(Modifier.height(12.dp))
                Text("Dia da perda", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    listOf(0 to "Hoje", 1 to "Ontem", 2 to "Anteontem").forEach { (n, rotulo) ->
                        FilterChip(
                            selected = form.diasAtras == n,
                            onClick = { aoMudarForm(form.copy(diasAtras = n)) },
                            modifier = Modifier.weight(1f).height(52.dp),
                            shape = MaterialTheme.shapes.medium,
                            label = {
                                Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                                    Text(rotulo, style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, maxLines = 1, softWrap = false)
                                    Text(diaTexto(n), style = MaterialTheme.typography.labelSmall, maxLines = 1)
                                }
                            },
                        )
                    }
                }
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = form.observacao,
                    onValueChange = { aoMudarForm(form.copy(observacao = it.take(500))) },
                    label = { Text("Observação (opcional)") },
                    modifier = Modifier.fillMaxWidth(),
                    shape = MaterialTheme.shapes.medium,
                )
                Spacer(Modifier.height(12.dp))
                if (erroAcao != null) {
                    Aviso(erroAcao, Situacao.ERRO)
                    Spacer(Modifier.height(8.dp))
                }
                if (aviso != null) {
                    Aviso(aviso, Situacao.OK)
                    Spacer(Modifier.height(8.dp))
                }
                BotaoPrincipal(
                    texto = calc.first?.let { "Apontar ${textoPerda(calc.first, calc.second)}" } ?: "Informe a quantidade ou o peso",
                    aoTocar = aoApontar,
                    habilitado = (calc.first ?: 0.0) > 0 || (pu == null && (numeroDe(form.quantidade) ?: 0.0) > 0),
                )
                Text("Gravar e excluir precisam da rede da fábrica.", style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant, textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().padding(top = 6.dp))
            }

            TituloSecao("Perdas apontadas nesta OP")
            if (dados.perdas.isEmpty()) {
                Text("Nenhuma perda apontada.", color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            dados.perdas.forEach { p -> LinhaPerda(p) { aoExcluir(p) } }
        }
    }
}

@Composable
internal fun LinhaPerda(p: PerdaApp, aoExcluir: () -> Unit) {
    val apagada = p.excluida
    val corTexto = if (apagada) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.onSurface
    Cartao(preenchimento = androidx.compose.foundation.layout.PaddingValues(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(p.texto, style = MaterialTheme.typography.titleMedium, color = corTexto,
                    textDecoration = if (apagada) TextDecoration.LineThrough else null)
                Text(
                    listOfNotNull(dataCurta(p.dia), p.maquina, if (p.origem == "aplicativo") "pelo app" else "pelo portal").joinToString(" · "),
                    style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant,
                    textDecoration = if (apagada) TextDecoration.LineThrough else null,
                )
            }
            if (apagada) Selo("Excluída", Situacao.ERRO)
            else TextButton(onClick = aoExcluir) { Text("Excluir", color = CoresOee.atual.erro) }
        }
        p.observacao?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = corTexto, modifier = Modifier.padding(top = 4.dp)) }
        HorizontalDivider(Modifier.padding(vertical = 8.dp), color = CoresOee.atual.borda)
        Text("Apontado por ${p.criadoPor ?: "—"}${p.criadoEm?.let { " em ${dataHora(it)}" } ?: ""}",
            style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        if (apagada) {
            Text("Excluído por ${p.excluidoPor ?: "—"}${p.excluidoEm?.let { " em ${dataHora(it)}" } ?: ""}",
                style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = CoresOee.atual.erro)
        }
    }
}

/** Janela de confirmação (gravar / excluir). */
@Composable
private fun Confirmacao(
    titulo: String,
    texto: String,
    botao: String,
    situacao: Situacao,
    ocupado: Boolean,
    erro: String?,
    aoConfirmar: () -> Unit,
    aoCancelar: () -> Unit,
) {
    JanelaMaquina(obrigatorio = ocupado, aoFechar = aoCancelar) {
        Column {
            CabecalhoDialogo(titulo, null, situacao)
            Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text(texto, style = MaterialTheme.typography.bodyLarge)
                if (erro != null) Aviso(erro, Situacao.ERRO)
                BotaoPrincipal(texto = botao, aoTocar = aoConfirmar, carregando = ocupado)
                TextButton(onClick = aoCancelar, enabled = !ocupado, modifier = Modifier.align(Alignment.CenterHorizontally)) { Text("Cancelar") }
            }
        }
    }
}

private val brasil = Locale("pt", "BR")

/** Quantidade e peso do formulário: o digitado e o calculado pelo PU. */
private fun calcular(f: FormPerda, pu: Double?): Pair<Double?, Double?> {
    val q = numeroDe(f.quantidade)
    val p = numeroDe(f.peso)
    if (pu == null) return q to null
    return if (f.digitado == "peso") (p?.let { it / pu }) to p else q to (q?.let { it * pu })
}

private fun textoPerda(q: Double?, p: Double?): String =
    listOfNotNull(q?.let { "${qtd(it, 2)} pç" }, p?.let { "${qtd(it, 4)} kg" }).joinToString(" · ")

/** Aceita "1,5", "1.5" e "1.234,5". */
private fun numeroDe(t: String): Double? {
    val s = t.trim()
    if (s.isEmpty()) return null
    val normal = if (s.contains(',')) s.replace(".", "").replace(',', '.') else s
    return normal.toDoubleOrNull()?.takeIf { it >= 0 }
}

private fun limparNumero(t: String) = t.filter { it.isDigit() || it == ',' || it == '.' }.take(12)

private fun qtd(v: Double, casas: Int = 3): String =
    NumberFormat.getNumberInstance(brasil).apply { maximumFractionDigits = casas }.format(v)

private fun pct(v: Double): String = NumberFormat.getPercentInstance(brasil).apply { maximumFractionDigits = 2 }.format(v)

private fun diaLocal(diasAtras: Int): LocalDate = LocalDate.now(ZoneId.of("America/Sao_Paulo")).minusDays(diasAtras.toLong())

private fun diaIso(diasAtras: Int) = diaLocal(diasAtras).toString()

private fun diaTexto(diasAtras: Int) = diaLocal(diasAtras).format(DateTimeFormatter.ofPattern("dd/MM"))

private fun dataCurta(iso: String) = runCatching { LocalDate.parse(iso.take(10)).format(DateTimeFormatter.ofPattern("dd/MM/yyyy")) }.getOrDefault(iso)

private fun dataHora(iso: String) = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.of("America/Sao_Paulo")).format(DateTimeFormatter.ofPattern("dd/MM HH:mm"))
}.getOrDefault(iso)
