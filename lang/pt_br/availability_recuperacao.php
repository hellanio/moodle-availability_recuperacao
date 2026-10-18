<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Strings for availability_recuperacao, Brazilian Portuguese.
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['description'] = 'Permitir apenas os alunos a quem a rodada de recuperação dá acesso: os enviados e, conforme as opções da rodada, os aprovados ou reprovados.';
$string['error_hidden'] = 'A restrição "Rodada de recuperação" deve ocultar totalmente a atividade dos alunos que não a atendem (olho fechado).';
$string['error_rootand'] = 'As restrições devem ser combinadas com "Estudante deve combinar tudo do seguinte"; caso contrário, alunos não enviados poderiam abrir a atividade.';
$string['error_selectround'] = 'Escolha a rodada de recuperação.';
$string['error_toplevel'] = 'A restrição "Rodada de recuperação" deve ficar no nível principal, fora de um grupo de restrição.';
$string['error_unavailableround'] = 'Esta rodada não usa esta atividade como atividade de recuperação, então ninguém obtém acesso por esta restrição. Escolha uma rodada da lista ou remova a restrição se nenhuma rodada usa esta atividade.';
$string['pluginname'] = 'Restrição por rodada de recuperação';
$string['privacy:metadata'] = 'O plugin Restrição por rodada de recuperação não armazena dados pessoais.';
$string['requires_notrecuperacao'] = 'Esta restrição não pode ser negada: ninguém obtém acesso por ela';
$string['requires_recuperacao'] = 'Você é aluno da rodada de recuperação (enviado ou, conforme as opções da rodada, aprovado ou reprovado)';
$string['title'] = 'Rodada de recuperação';
$string['unavailableround'] = 'Rodada indisponível (#{$a})';
