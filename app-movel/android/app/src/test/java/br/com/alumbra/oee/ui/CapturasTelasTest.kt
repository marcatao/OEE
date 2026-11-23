package br.com.alumbra.oee.ui

import app.cash.paparazzi.DeviceConfig
import app.cash.paparazzi.Paparazzi
import br.com.alumbra.oee.atualizacao.CartaoAtualizacao
import br.com.alumbra.oee.atualizacao.EstadoAtualizacao
import br.com.alumbra.oee.atualizacao.InfoAtualizacao
import br.com.alumbra.oee.atualizacao.TelaAtualizacaoObrigatoria
import br.com.alumbra.oee.rede.EstadoRede
import br.com.alumbra.oee.sessao.UsuarioApp
import org.junit.Rule
import org.junit.Test

/** Capturas das telas para revisar o visual sem celular: ./gradlew recordPaparazziDebug */
class CapturasTelasTest {

    @get:Rule
    val paparazzi = Paparazzi(deviceConfig = DeviceConfig.PIXEL_5, maxPercentDifference = 0.1)

    private val usuario = UsuarioApp(
        nome = "Maria Aparecida Souza", primeiroNome = "Maria", email = "maria.souza@alumbra.com.br",
        perfis = listOf("Operador", "Mantenedor de Produção"), unidades = listOf("manutencao"),
        operador = true, timeline = true, mantenedor = true, aprovarOs = true, solicitarOs = false,
    )
    private val agora = 1_763_400_000_000L

    @Test
    fun entrada_na_rede_da_fabrica() = paparazzi.snapshot {
        TemaOee(escuro = false) {
            TelaInicial(EstadoRede(true, "Wlan_alumbra", false, true), null, false, {}, {}, {})
        }
    }

    @Test
    fun entrada_fora_da_rede() = paparazzi.snapshot {
        TemaOee(escuro = false) {
            TelaInicial(EstadoRede(true, "Visitantes", false, false), "Seu primeiro acesso deve ser pelo portal OEE Alumbra no computador.", false, {}, {}, {})
        }
    }

    @Test
    fun principal() = paparazzi.snapshot {
        TemaOee(escuro = false) {
            TelaPrincipal(usuario, agora - 5 * 60_000, 48, false, false, null, true, {}, {}, agora = agora)
        }
    }

    @Test
    fun principal_bloqueada_escuro() = paparazzi.snapshot {
        TemaOee(escuro = true) {
            TelaPrincipal(usuario, agora - 50 * 3_600_000L, 48, true, false, null, false, {}, {}, agora = agora)
        }
    }

    private val novaVersao = InfoAtualizacao(23, "0.13.0", 8_840_000, "abc", "Atualização pelo portal e gráfico com a meta.", true, false)

    @Test
    fun principal_com_atualizacao() = paparazzi.snapshot {
        TemaOee(escuro = false) {
            TelaPrincipal(usuario, agora - 5 * 60_000, 48, false, false, null, true, {}, {}, agora = agora,
                cartaoAtualizacao = { CartaoAtualizacao(EstadoAtualizacao(novaVersao, progresso = 0.42f)) {} })
        }
    }

    @Test
    fun atualizacao_obrigatoria() = paparazzi.snapshot {
        TemaOee(escuro = false) {
            TelaAtualizacaoObrigatoria(EstadoAtualizacao(novaVersao.copy(obrigatoria = true)), {}, {})
        }
    }
}
