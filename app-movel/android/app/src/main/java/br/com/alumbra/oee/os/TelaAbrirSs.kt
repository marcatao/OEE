package br.com.alumbra.oee.os

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
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
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
import androidx.compose.ui.unit.dp
import br.com.alumbra.oee.api.ErroApi
import br.com.alumbra.oee.ui.Aviso
import br.com.alumbra.oee.ui.BarraTopo
import br.com.alumbra.oee.ui.BotaoPrincipal
import br.com.alumbra.oee.ui.Cartao
import br.com.alumbra.oee.ui.Situacao
import kotlinx.coroutines.launch
import org.json.JSONObject

/** Abrir uma Solicitação de Serviço (SS): mesmas regras do portal (área do solicitante, máquina da área, CC da máquina). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun TelaAbrirSs(repositorio: RepositorioOs, aoVoltar: () -> Unit, aoEnviada: (String) -> Unit, aoPerderAcesso: (String) -> Unit) {
    var form by remember { mutableStateOf<FormularioSs?>(null) }
    var erro by remember { mutableStateOf<String?>(null) }
    var enviando by remember { mutableStateOf(false) }
    var area by remember { mutableStateOf<String?>(null) }
    var maquina by remember { mutableStateOf<Int?>(null) }
    var busca by remember { mutableStateOf("") }
    var tipo by remember { mutableStateOf("corretiva") }
    var prioridade by remember { mutableStateOf("media") }
    var parada by remember { mutableStateOf(false) }
    var titulo by remember { mutableStateOf("") }
    var descricao by remember { mutableStateOf("") }
    val escopo = rememberCoroutineScope()

    LaunchedEffect(Unit) {
        try {
            form = repositorio.formulario()
        } catch (e: ErroApi) {
            if (e.semAcesso) aoPerderAcesso(e.message ?: "") else erro = e.message
        }
    }

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        BarraTopo("Abrir solicitação (SS)", "Vai para a manutenção analisar", aoVoltar)
        val f = form
        if (f == null) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                if (erro != null) Aviso(erro!!, Situacao.ERRO, Modifier.padding(16.dp)) else CircularProgressIndicator()
            }
            return
        }
        Column(
            Modifier.verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding().padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            if (!f.podeSolicitar) {
                Aviso("Para abrir SS você precisa do perfil Solicitante no portal.", Situacao.ALERTA)
                return@Column
            }
            Cartao {
                Rotulo("Área executante (quem vai fazer)")
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    f.areas.forEach { a -> Escolha(a.rotulo, area == a.valor) { area = a.valor; maquina = null } }
                }
                Spacer(Modifier.height(14.dp))
                Rotulo("Máquina (opcional)")
                val daArea = f.maquinas.filter { area == null || it.areas.contains(area!!.toInt()) }
                    .filter { busca.isBlank() || "${it.codigo} ${it.nome}".contains(busca, ignoreCase = true) }
                OutlinedTextField(busca, { busca = it }, placeholder = { Text("Buscar máquina") }, singleLine = true,
                    modifier = Modifier.fillMaxWidth(), shape = MaterialTheme.shapes.medium)
                Spacer(Modifier.height(6.dp))
                Column(Modifier.fillMaxWidth()) {
                    Escolha("Sem máquina", maquina == null) { maquina = null }
                    daArea.take(8).forEach { m -> Spacer(Modifier.height(6.dp)); Escolha("${m.codigo} · ${m.nome}", maquina == m.id) { maquina = m.id } }
                    if (daArea.size > 8) Text("…e mais ${daArea.size - 8}: use a busca.", style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(top = 6.dp))
                }
                if (maquina != null) {
                    Row(Modifier.fillMaxWidth().padding(top = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text("A máquina está parada", style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                        Switch(parada, { parada = it })
                    }
                }
            }
            Cartao {
                Rotulo("Tipo")
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    f.tipos.forEach { t -> Escolha(t.rotulo, tipo == t.valor) { tipo = t.valor } }
                }
                Spacer(Modifier.height(12.dp))
                Rotulo("Prioridade")
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    f.prioridades.forEach { p -> Escolha(p.rotulo, prioridade == p.valor) { prioridade = p.valor } }
                }
            }
            Cartao {
                OutlinedTextField(titulo, { titulo = it.take(120) }, label = { Text("Título resumido") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(descricao, { descricao = it.take(2000) }, label = { Text("Descreva o problema ou o serviço") },
                    minLines = 4, modifier = Modifier.fillMaxWidth())
            }
            if (erro != null) Aviso(erro!!, Situacao.ERRO)
            BotaoPrincipal(
                "Enviar solicitação",
                {
                    escopo.launch {
                        enviando = true
                        try {
                            val dados = JSONObject().put("titulo", titulo.trim()).put("tipo", tipo).put("prioridade", prioridade)
                                .put("area_executante_id", area?.toInt()).put("maquina_id", maquina ?: JSONObject.NULL)
                                .put("maquina_parada", maquina != null && parada).put("descricao", descricao.trim())
                            aoEnviada(repositorio.abrirSs(dados))
                        } catch (e: ErroApi) {
                            if (e.semAcesso) aoPerderAcesso(e.message ?: "") else erro = e.message
                        } finally {
                            enviando = false
                        }
                    }
                },
                habilitado = area != null && titulo.isNotBlank() && descricao.isNotBlank(),
                carregando = enviando,
            )
        }
    }
}

@Composable
private fun Rotulo(texto: String) {
    Text(texto, style = MaterialTheme.typography.titleMedium, modifier = Modifier.padding(bottom = 8.dp))
}

@Composable
private fun Escolha(texto: String, marcada: Boolean, aoTocar: () -> Unit) {
    Text(
        texto,
        style = MaterialTheme.typography.labelLarge,
        color = if (marcada) MaterialTheme.colorScheme.onPrimary else MaterialTheme.colorScheme.onSurface,
        modifier = Modifier
            .clip(MaterialTheme.shapes.medium)
            .background(if (marcada) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surfaceVariant)
            .border(1.dp, if (marcada) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outlineVariant, MaterialTheme.shapes.medium)
            .clickable(onClick = aoTocar)
            .padding(horizontal = 14.dp, vertical = 12.dp),
    )
}
