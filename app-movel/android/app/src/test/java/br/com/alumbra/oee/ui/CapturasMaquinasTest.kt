package br.com.alumbra.oee.ui

import app.cash.paparazzi.DeviceConfig
import app.cash.paparazzi.Paparazzi
import br.com.alumbra.oee.maquinas.ApontamentoApp
import br.com.alumbra.oee.maquinas.ConteudoMaquina
import br.com.alumbra.oee.maquinas.ConteudoMaquinas
import br.com.alumbra.oee.maquinas.DetalheMaquina
import br.com.alumbra.oee.maquinas.DialogoCavidades
import br.com.alumbra.oee.maquinas.DialogoMotivo
import br.com.alumbra.oee.maquinas.LegendaApp
import br.com.alumbra.oee.maquinas.MaquinaResumo
import br.com.alumbra.oee.maquinas.MoldeApp
import br.com.alumbra.oee.maquinas.OpDetalhe
import br.com.alumbra.oee.maquinas.OpResumo
import br.com.alumbra.oee.maquinas.OperacaoApp
import org.junit.Rule
import org.junit.Test

/** Capturas da função Máquinas: ./gradlew recordPaparazziDebug */
class CapturasMaquinasTest {

    @get:Rule
    val paparazzi = Paparazzi(deviceConfig = DeviceConfig.PIXEL_5.copy(screenHeight = 2340), maxPercentDifference = 0.1)

    private val agora = 1_763_400_000_000L
    private val produzindo = LegendaApp(1, "1", "Produzindo", "produzindo", "#2e7d32")
    private val semMotivo = LegendaApp(2, "98", "Parada sem motivo", "parada", "#b42318")
    private val quebra = LegendaApp(3, "10", "Quebra de molde", "parada", "#d97706")
    private val motivos = listOf(quebra, LegendaApp(4, "11", "Falta de material", "parada", "#7c3aed"),
        LegendaApp(5, "12", "Manutenção corretiva", "parada", "#be123c"), LegendaApp(6, "13", "Ajuste de processo", "parada", "#0f766e"))
    private val op = OpResumo(1, "06267801001", "PA0910755", "INTERRUPTOR PARALELO 10A", "Andamento", 12000.0, 7350.0)

    private fun iso(msAtras: Long) = java.time.Instant.ofEpochMilli(agora - msAtras).toString()

    private val apontamentos = listOf(
        ApontamentoApp(10, semMotivo, true, "17/11", "14:20:01", null, iso(25 * 60_000), null, 25.0, "06267801001"),
        ApontamentoApp(9, produzindo, false, "17/11", "08:00:00", "14:20:00", iso(6 * 3_600_000 + 45 * 60_000), iso(25 * 60_000 + 1000), 380.0, "06267801001"),
        ApontamentoApp(8, quebra, false, "17/11", "07:10:00", "07:59:59", iso(7 * 3_600_000 + 35 * 60_000), iso(6 * 3_600_000 + 45 * 60_000), 50.0, null),
        ApontamentoApp(7, produzindo, false, "17/11", "06:00:00", "07:09:59", iso(11 * 3_600_000 + 20 * 60_000), iso(7 * 3_600_000 + 35 * 60_000), 70.0, null),
    )

    private fun detalhe(comOp: Boolean, pendente: Boolean) = DetalheMaquina(
        id = 1, codigo = "INJ-01", nome = "Injetora 01", local = "Linha 1 · Planta SBC",
        situacao = "parada", situacaoDesde = "17/11 14:20", legenda = if (pendente) semMotivo else quebra,
        apontamentos = apontamentos, semMotivo = if (pendente) apontamentos.take(1) else emptyList(),
        op = if (comOp) OpDetalhe(op, "01", listOf(
            OperacaoApp("10", "INJETAR TAMPA", "INJ-01", "M001", true),
            OperacaoApp("20", "MONTAGEM", "MON-02", "", false),
        ), listOf(MoldeApp(1, "M001", "MOLDE TAMPA 8 CAV", 8, 6))) else null,
        legendasMotivo = motivos, servidorAgora = "", segundosAtualizacao = 10,
        periodoDe = iso(11 * 3_600_000L + 20 * 60_000), periodoAte = iso(-(4 * 3_600_000L + 41 * 60_000)), periodoRotulo = "Turno de hoje, 17/11 · 06:00 às 22:00",
    )

    @Test
    fun lista_de_maquinas() = paparazzi.snapshot {
        TemaOee(escuro = false) {
            ConteudoMaquinas(
                maquinas = listOf(
                    MaquinaResumo(1, "INJ-01", "Injetora 01", "Linha 1 · Planta SBC", "parada", semMotivo, op, 1),
                    MaquinaResumo(2, "INJ-02", "Injetora 02", "Linha 1 · Planta SBC", "produzindo", produzindo, null, 0),
                    MaquinaResumo(3, "INJ-03", "Injetora 03", "Linha 2 · Planta SBC", "produzindo", produzindo, op.copy(numero = "06267901001"), 0),
                    MaquinaResumo(4, "PRE-01", "Prensa 01", "Linha 3 · Planta SBC", "parada", quebra, op.copy(numero = "06268001001"), 0),
                ),
                erro = null, busca = "", aoBuscar = {}, carregando = false, aoVoltar = {}, aoAtualizar = {}, aoAbrir = {},
            )
        }
    }

    @Test
    fun maquina_com_op() = paparazzi.snapshot {
        TemaOee(escuro = false) {
            ConteudoMaquina("Injetora 01", detalhe(comOp = true, pendente = false), null, agora, true, {}, {}, {}, {}, agora = agora)
        }
    }

    @Test
    fun maquina_parada_sem_motivo() = paparazzi.snapshot {
        TemaOee(escuro = false) {
            androidx.compose.foundation.layout.Box {
                ConteudoMaquina("Injetora 01", detalhe(comOp = true, pendente = true), null, agora, true, {}, {}, {}, {}, agora = agora)
                DialogoMotivo(apontamentos.first(), motivos, 1, false, null) {}
            }
        }
    }

    @Test
    fun cavidades() = paparazzi.snapshot {
        TemaOee(escuro = false) {
            androidx.compose.foundation.layout.Box {
                ConteudoMaquina("Injetora 01", detalhe(comOp = true, pendente = false), null, agora, true, {}, {}, {}, {}, agora = agora)
                DialogoCavidades(MoldeApp(1, "M001", "MOLDE TAMPA 8 CAV", 8, 6), false, null, {}, {})
            }
        }
    }
}
