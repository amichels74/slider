<?php
require_once 'config.php';
checkAuth();
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

function calcularValorAtleta($db, $atletaId, $campId, $mes, $cotaCheia, $jogos) {
    $stmt = $db->prepare("SELECT status, status_desde FROM inscricoes WHERE atleta_id = ? AND campeonato_id = ?");
    $stmt->execute([$atletaId, $campId]);
    $ins = $stmt->fetch();
    if (!$ins) return 0;

    $status = $ins['status'];
    $desde  = $ins['status_desde'];

    if ($status === 'ativa') return $cotaCheia;
    if (!$desde) return 0;

    $jogosAntes = array_filter($jogos, fn($j) => $j['data_jogo'] < $desde);
    $totalJogos = count($jogos);
    $jogosAntesCount = count($jogosAntes);

    if ($jogosAntesCount === 0) return 0;
    if ($status === 'inativa') return $cotaCheia;

    if ($status === 'ausente' && $totalJogos > 0) {
        return round($cotaCheia * ($jogosAntesCount / $totalJogos), 2);
    }

    return 0;
}

function tipoNoMes($db, $atletaId, $campId, $mes) {
    $stmt = $db->prepare("SELECT tipo, tipo_a_partir_de FROM inscricoes WHERE atleta_id = ? AND campeonato_id = ?");
    $stmt->execute([$atletaId, $campId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if (!$row['tipo_a_partir_de'] || $row['tipo_a_partir_de'] <= $mes) return $row['tipo'];
    $hist = $db->prepare("SELECT tipo_anterior FROM historico_tipo WHERE atleta_id=? AND campeonato_id=? AND mes_vigencia>? ORDER BY mes_vigencia ASC LIMIT 1");
    $hist->execute([$atletaId, $campId, $mes]);
    $anterior = $hist->fetchColumn();
    return $anterior ?: $row['tipo'];
}

// Returns [normal_games[], ferias_games[]] separated
function separarJogos($jogos) {
    $normais = [];
    $ferias  = [];
    foreach ($jogos as $j) {
        if (!empty($j['mes_ferias'])) $ferias[] = $j;
        else $normais[] = $j;
    }
    return [$normais, $ferias];
}

// Calcula rateio de um jogo de férias: retorna array [atleta_id => valor]
// Avulsas pagam valor_avulsa do jogo; o restante é dividido entre os demais presentes.
function rateioPorPresenca($db, $jogo) {
    // Busca presentes — marca como avulsa se tiver inscrição avulsa ativa em qualquer campeonato
    $stmt = $db->prepare("
        SELECT p.atleta_id,
               CASE WHEN EXISTS (
                   SELECT 1 FROM inscricoes i
                   WHERE i.atleta_id = p.atleta_id AND i.tipo = 'avulsa' AND i.status = 'ativa'
               ) THEN 'avulsa' ELSE 'mensalista' END AS tipo_insc
        FROM participacoes p
        WHERE p.jogo_id = ? AND p.tipo = 'ferias'
    ");
    $stmt->execute([$jogo['id']]);
    $presentes = $stmt->fetchAll();
    if (!$presentes) return [];

    $custoTotal  = $jogo['custo_jogo'] + $jogo['custo_tecnico'];
    $valorAvulsa = floatval($jogo['valor_avulsa'] ?? 0);

    $avulsas      = array_filter($presentes, fn($p) => $p['tipo_insc'] === 'avulsa');
    $naoAvulsas   = array_filter($presentes, fn($p) => $p['tipo_insc'] !== 'avulsa');

    $recAvulsas  = count($avulsas) * $valorAvulsa;
    $custoresto  = $custoTotal - $recAvulsas;
    $nResto      = count($naoAvulsas);
    $parteResto  = ($nResto > 0 && $custoresto > 0) ? round($custoresto / $nResto, 3) : 0;

    $result = [];
    foreach ($avulsas as $p) {
        $result[$p['atleta_id']] = $valorAvulsa;
    }
    foreach ($naoAvulsas as $p) {
        $result[$p['atleta_id']] = $parteResto;
    }
    return $result;
}

if ($method === 'GET') {
    $mes      = $_GET['mes'] ?? date('Y-m');
    $listagem = $_GET['listagem'] ?? 0;

    if ($listagem) {
        $stmt = $db->prepare("
            SELECT f.id, f.mes_referencia, f.campeonato_id, c.nome AS camp_nome,
                   f.atleta_id, a.nome AS atleta_nome, f.tipo, f.valor, f.pago, f.data_pagamento
            FROM fechamentos f
            JOIN campeonatos c ON c.id = f.campeonato_id
            JOIN atletas a     ON a.id = f.atleta_id
            WHERE f.mes_referencia = ?
            ORDER BY f.pago ASC, c.nome, a.nome
        ");
        $stmt->execute([$mes]);
        $rows      = $stmt->fetchAll();
        $pendentes = array_values(array_filter($rows, fn($r) => !$r['pago']));
        $pagos     = array_values(array_filter($rows, fn($r) =>  $r['pago']));
        jsonResponse([
            'mes'            => $mes,
            'pendentes'      => $pendentes,
            'pagos'          => $pagos,
            'total_pendente' => array_sum(array_column($pendentes, 'valor')),
            'total_pago'     => array_sum(array_column($pagos, 'valor')),
            'gerado'         => count($rows) > 0,
        ]);
    }

    // Preview calculado
    $camps     = $db->query("SELECT id, nome FROM campeonatos WHERE ativo = 1")->fetchAll();
    $resultado = [];

    foreach ($camps as $camp) {
        $cId = $camp['id'];

        $stmtJ = $db->prepare("SELECT id, data_jogo, custo_jogo, custo_tecnico, valor_avulsa, COALESCE(mes_ferias,0) AS mes_ferias FROM jogos WHERE campeonato_id = ? AND mes_referencia = ? ORDER BY data_jogo");
        $stmtJ->execute([$cId, $mes]);
        $todosJogos = $stmtJ->fetchAll();
        if (!$todosJogos) continue;

        [$jogos, $jogosFerias] = separarJogos($todosJogos);

        // Custo de jogos normais
        $custoTotal = array_sum(array_map(fn($j) => $j['custo_jogo'] + $j['custo_tecnico'], $jogos));

        // Extras
        $extrasTotal = 0;
        $extrasLista = [];
        try {
            $stmtEx = $db->prepare("SELECT id, descricao, valor, data_lancamento FROM ajustes WHERE tipo='extra_camp' AND campeonato_id=? AND mes_referencia=?");
            $stmtEx->execute([$cId, $mes]);
            $extrasLista = $stmtEx->fetchAll();
            $extrasTotal = array_sum(array_column($extrasLista, 'valor'));
            $custoTotal += $extrasTotal;
        } catch (Exception $e) {}

        // Receita de avulsas (apenas jogos normais — tipo='avulsa')
        $stmtRec = $db->prepare("SELECT SUM(p.valor) FROM participacoes p JOIN jogos j ON j.id=p.jogo_id WHERE j.campeonato_id=? AND j.mes_referencia=? AND p.tipo='avulsa'");
        $stmtRec->execute([$cId, $mes]);
        $recAvulsas = floatval($stmtRec->fetchColumn() ?? 0);

        $todos = $db->prepare("
            SELECT a.id, a.nome FROM atletas a
            JOIN inscricoes i ON i.atleta_id = a.id
            WHERE i.campeonato_id = ?
            AND (i.status = 'ativa'
                 OR (i.status IN ('ausente','inativa') AND i.status_desde IS NOT NULL))
        ");
        $todos->execute([$cId]);
        $candidatos = array_filter($todos->fetchAll(), fn($a) => tipoNoMes($db, $a['id'], $cId, $mes) === 'mensalista');

        $liquido    = $custoTotal - $recAvulsas;
        $totalJogos = count($jogos);

        $ativos   = [];
        $parciais = [];
        foreach ($candidatos as $a) {
            $ins = $db->prepare("SELECT status, status_desde, retorno_desde FROM inscricoes WHERE atleta_id=? AND campeonato_id=?");
            $ins->execute([$a['id'], $cId]);
            $row    = $ins->fetch();
            $status  = $row['status']         ?? 'ativa';
            $desde   = $row['status_desde']   ?? null;
            $retorno = $row['retorno_desde']   ?? null;

            if ($status === 'ativa' && !$retorno) {
                $ativos[] = $a;
            } elseif ($status === 'ativa' && $retorno) {
                $jogosForaAusencia = count(array_filter($jogos, function($j) use ($desde, $retorno) {
                    $antes  = !$desde  || $j['data_jogo'] < $desde;
                    $depois = $j['data_jogo'] >= $retorno;
                    return $antes || $depois;
                }));
                if ($jogosForaAusencia > 0 && $totalJogos > 0) {
                    $parciais[] = ['atleta' => $a, 'fracao' => $jogosForaAusencia / $totalJogos, 'status' => 'retorno'];
                }
            } else {
                $jogosAntes = $desde ? count(array_filter($jogos, fn($j) => $j['data_jogo'] < $desde)) : 0;
                if ($jogosAntes > 0) {
                    $parciais[] = ['atleta' => $a, 'jogos_antes' => $jogosAntes, 'total_jogos' => $totalJogos, 'status' => $status];
                }
            }
        }

        $parciaisValores = [];
        foreach ($parciais as $p) {
            if ($p['status'] === 'retorno') {
                $parciaisValores[] = ['atleta' => $p['atleta'], 'fracao' => $p['fracao'], 'status' => 'retorno'];
            } elseif ($p['status'] === 'inativa') {
                $parciaisValores[] = ['atleta' => $p['atleta'], 'fracao' => 1.0, 'status' => 'inativa'];
            } else {
                $fracao = $p['total_jogos'] > 0 ? $p['jogos_antes'] / $p['total_jogos'] : 0;
                $parciaisValores[] = ['atleta' => $p['atleta'], 'fracao' => $fracao, 'status' => 'ausente'];
            }
        }

        $nAtivos     = count($ativos);
        $somFracoes  = array_sum(array_column($parciaisValores, 'fracao'));
        $divisor     = $nAtivos + $somFracoes;
        $cotaBase    = $divisor > 0 ? $liquido / $divisor : 0;

        $mensalistas = [];
        foreach ($ativos as $a) {
            $mensalistas[] = ['id'=>$a['id'],'nome'=>$a['nome'],'valor'=>round($cotaBase, 3)];
        }
        foreach ($parciaisValores as $p) {
            $valor = round($cotaBase * $p['fracao'], 3);
            if ($valor > 0) {
                $mensalistas[] = ['id'=>$p['atleta']['id'],'nome'=>$p['atleta']['nome'],'valor'=>$valor];
            }
        }

        // Jogos de férias: rateio individual por presença
        $cobFeriasMap = []; // atleta_id => valor total
        foreach ($jogosFerias as $jf) {
            foreach (rateioPorPresenca($db, $jf) as $aid => $val) {
                $cobFeriasMap[$aid] = ($cobFeriasMap[$aid] ?? 0) + $val;
            }
        }
        $cobFerias = [];
        if (!empty($cobFeriasMap)) {
            // Busca nomes
            $ids = array_keys($cobFeriasMap);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmtN = $db->prepare("SELECT id, nome FROM atletas WHERE id IN ($placeholders)");
            $stmtN->execute($ids);
            $nomes = array_column($stmtN->fetchAll(), 'nome', 'id');
            foreach ($cobFeriasMap as $aid => $val) {
                $cobFerias[] = ['id'=>$aid,'nome'=>$nomes[$aid]??$aid,'valor'=>round($val,3)];
            }
            usort($cobFerias, fn($a,$b) => strcmp($a['nome'],$b['nome']));
        }

        $stmtAv = $db->prepare("SELECT a.id, a.nome, SUM(p.valor) AS total_pago FROM participacoes p JOIN atletas a ON a.id=p.atleta_id JOIN jogos j ON j.id=p.jogo_id WHERE j.campeonato_id=? AND j.mes_referencia=? AND p.tipo='avulsa' GROUP BY a.id, a.nome");
        $stmtAv->execute([$cId, $mes]);

        $resultado[] = [
            'campeonato_id'   => $cId,
            'camp_nome'       => $camp['nome'],
            'jogos'           => count($jogos),
            'jogos_ferias'    => count($jogosFerias),
            'custo_total'     => $custoTotal,
            'rec_avulsas'     => $recAvulsas,
            'custo_liquido'   => $liquido,
            'n_mensalistas'   => $nAtivos + count($parciaisValores),
            'cota_mensalista' => $cotaBase,
            'mensalistas'     => $mensalistas,
            'avulsas'         => $stmtAv->fetchAll(),
            'ferias'          => $cobFerias,
            'extras_total'    => $extrasTotal,
            'extras'          => $extrasLista,
        ];
    }
    jsonResponse(['mes' => $mes, 'campeonatos' => $resultado]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $mes   = $input['mes'] ?? date('Y-m');

    $existe = $db->prepare("SELECT COUNT(*) FROM fechamentos WHERE mes_referencia = ?");
    $existe->execute([$mes]);
    if ($existe->fetchColumn() > 0)
        jsonResponse(['erro' => 'Fechamento já gerado. Apague os pendentes primeiro.'], 400);

    $camps       = $db->query("SELECT id FROM campeonatos WHERE ativo = 1")->fetchAll();
    $totalGerado = 0;

    foreach ($camps as $camp) {
        $cId = $camp['id'];

        $stmtJ = $db->prepare("SELECT id, data_jogo, custo_jogo, custo_tecnico, COALESCE(mes_ferias,0) AS mes_ferias FROM jogos WHERE campeonato_id=? AND mes_referencia=? ORDER BY data_jogo");
        $stmtJ->execute([$cId, $mes]);
        $todosJogos = $stmtJ->fetchAll();
        if (!$todosJogos) continue;

        [$jogos, $jogosFerias] = separarJogos($todosJogos);

        // ---- Jogos normais: cota mensalista ----
        if ($jogos) {
            $custoTotal = array_sum(array_map(fn($j) => $j['custo_jogo'] + $j['custo_tecnico'], $jogos));
            $stmtRec    = $db->prepare("SELECT SUM(p.valor) FROM participacoes p JOIN jogos j ON j.id=p.jogo_id WHERE j.campeonato_id=? AND j.mes_referencia=? AND p.tipo='avulsa'");
            $stmtRec->execute([$cId, $mes]);
            $recAvulsas = floatval($stmtRec->fetchColumn() ?? 0);

            $todos = $db->prepare("SELECT a.id FROM atletas a JOIN inscricoes i ON i.atleta_id=a.id WHERE i.campeonato_id=? AND (i.status='ativa' OR (i.status IN ('ausente','inativa') AND i.status_desde IS NOT NULL))");
            $todos->execute([$cId]);
            $candidatos = array_filter($todos->fetchAll(PDO::FETCH_COLUMN), fn($aid) => tipoNoMes($db, $aid, $cId, $mes)==='mensalista');

            $liquido2    = $custoTotal - $recAvulsas;
            $totalJogos2 = count($jogos);
            $ativos2     = [];
            $parciais2   = [];
            foreach ($candidatos as $aid) {
                $ins2 = $db->prepare("SELECT status, status_desde, retorno_desde FROM inscricoes WHERE atleta_id=? AND campeonato_id=?");
                $ins2->execute([$aid, $cId]);
                $row2    = $ins2->fetch();
                $status2  = $row2['status']         ?? 'ativa';
                $desde2   = $row2['status_desde']   ?? null;
                $retorno2 = $row2['retorno_desde']  ?? null;

                if ($status2 === 'ativa' && !$retorno2) {
                    $ativos2[] = $aid;
                } elseif ($status2 === 'ativa' && $retorno2) {
                    $jogosForaAus2 = count(array_filter($jogos, function($j) use ($desde2, $retorno2) {
                        return (!$desde2 || $j['data_jogo'] < $desde2) || $j['data_jogo'] >= $retorno2;
                    }));
                    if ($jogosForaAus2 > 0 && $totalJogos2 > 0)
                        $parciais2[] = ['id' => $aid, 'fracao' => $jogosForaAus2 / $totalJogos2];
                } else {
                    $jogosAntes2 = $desde2 ? count(array_filter($jogos, fn($j) => $j['data_jogo'] < $desde2)) : 0;
                    if ($jogosAntes2 > 0) {
                        $fracao2 = $status2==='inativa' ? 1.0 : ($totalJogos2>0 ? $jogosAntes2/$totalJogos2 : 0);
                        $parciais2[] = ['id' => $aid, 'fracao' => $fracao2];
                    }
                }
            }
            $somFracoes2 = array_sum(array_column($parciais2, 'fracao'));
            $divisor2    = count($ativos2) + $somFracoes2;
            $cotaBase2   = $divisor2 > 0 ? $liquido2 / $divisor2 : 0;

            $stmtIns = $db->prepare("INSERT IGNORE INTO fechamentos (mes_referencia, campeonato_id, atleta_id, tipo, valor) VALUES (?,?,?,'mensalista',?)");
            foreach ($ativos2 as $aid) {
                $stmtIns->execute([$mes, $cId, $aid, round($cotaBase2,3)]); $totalGerado++;
            }
            foreach ($parciais2 as $p) {
                $val2 = round($cotaBase2 * $p['fracao'], 3);
                if ($val2 > 0) { $stmtIns->execute([$mes, $cId, $p['id'], $val2]); $totalGerado++; }
            }

            // Avulsas normais
            $stmtAv  = $db->prepare("SELECT a.id, SUM(p.valor) AS total FROM participacoes p JOIN atletas a ON a.id=p.atleta_id JOIN jogos j ON j.id=p.jogo_id WHERE j.campeonato_id=? AND j.mes_referencia=? AND p.tipo='avulsa' GROUP BY a.id");
            $stmtAv->execute([$cId, $mes]);
            $stmtInsAv = $db->prepare("INSERT IGNORE INTO fechamentos (mes_referencia, campeonato_id, atleta_id, tipo, valor) VALUES (?,?,?,'avulsa',?)");
            foreach ($stmtAv->fetchAll() as $av) { $stmtInsAv->execute([$mes, $cId, $av['id'], $av['total']]); $totalGerado++; }
        }

        // ---- Jogos de férias: rateio por presença ----
        if ($jogosFerias) {
            $feriasMap = [];
            foreach ($jogosFerias as $jf) {
                foreach (rateioPorPresenca($db, $jf) as $aid => $val) {
                    $feriasMap[$aid] = ($feriasMap[$aid] ?? 0) + $val;
                }
            }
            $stmtInsF = $db->prepare("INSERT IGNORE INTO fechamentos (mes_referencia, campeonato_id, atleta_id, tipo, valor) VALUES (?,?,?,'ferias',?)");
            foreach ($feriasMap as $aid => $val) {
                $val = round($val, 3);
                if ($val > 0) { $stmtInsF->execute([$mes, $cId, $aid, $val]); $totalGerado++; }
            }
        }
    }

    jsonResponse(['ok' => true, 'total_cobrancas' => $totalGerado]);
}

if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id    = intval($input['id']   ?? 0);
    $pago  = intval($input['pago'] ?? 0);
    if (!$id) jsonResponse(['erro' => 'id obrigatório'], 400);
    $db->prepare("UPDATE fechamentos SET pago=?, data_pagamento=? WHERE id=?")
       ->execute([$pago, $pago ? date('Y-m-d') : null, $id]);
    jsonResponse(['ok' => true]);
}

if ($method === 'DELETE') {
    $mes = $_GET['mes'] ?? '';
    if (!$mes) jsonResponse(['erro' => 'mes obrigatório'], 400);
    $db->prepare("DELETE FROM fechamentos WHERE mes_referencia=? AND pago=0")->execute([$mes]);
    jsonResponse(['ok' => true]);
}
