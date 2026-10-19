@availability @availability_recuperacao @qa_m14c
Feature: QA M1.4c - editor of the restriction "Recovery round" beyond J1-J5
  In order to trust the editor of the restriction "Recovery round"
  As the independent QA
  I need the saved tree to survive reopening the form, more conditions, two rounds, the closed eye, the trash bin,
  teachers without the capability, the plugin switched off and the Brazilian Portuguese interface

  Background:
    Given the following "courses" exist:
      | fullname | shortname | format | numsections |
      | Curso 1  | C1        | topics | 2           |
    And the following "users" exist:
      | username | firstname | lastname | email             | lang  |
      | teacher1 | Teacher   | One      | t1@example.com    | en    |
      | teacher2 | Tutor     | Two      | t2@example.com    | en    |
      | profpt   | Prof      | Pt       | pt@example.com    | pt_br |
      | ana      | Ana       | Almeida  | ana@example.com   | en    |
      | bruno    | Bruno     | Barbosa  | bruno@example.com | en    |
      | carla    | Carla     | Costa    | carla@example.com | en    |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher2 | C1     | teacher        |
      | profpt   | C1     | editingteacher |
      | ana      | C1     | student        |
      | bruno    | C1     | student        |
      | carla    | C1     | student        |
    And the following "activities" exist:
      | activity | name           | course | idnumber | content          | section |
      | page     | Atividade alvo | C1     | rec      | Conteudo do alvo | 1       |
    And the following "activities" exist:
      | activity    | name           | course | idnumber |
      | recuperacao | Rodada do alvo | C1     | round1   |
      | recuperacao | Segunda rodada | C1     | round2   |
    And the recovery activity of "Rodada do alvo" is "Atividade alvo"
    And the following "mod_recuperacao > cases" exist:
      | round  | user  | status     |
      | round1 | ana   | sent       |
      | round1 | bruno | identified |
      | round2 | carla | sent       |

  @javascript
  Scenario: QJ1 - saved, reopened and saved again without change: same tree, no warning, eye still closed
    Given I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And I click on "Add restriction..." "button"
    And I click on "Recovery round" "button" in the "Add restriction..." "dialogue"
    And I press "Save and return to course"
    And the restrictions of "rec" should be only the recovery round "round1" hidden
    And the recovery guard of "rec" should be "ok"
    And I should see "You are a student of the recovery round" in the "Atividade alvo" "activity"
    When I am on the "rec" "page activity editing" page
    And I expand all fieldsets
    Then I should see "Rodada do alvo" in the "Restrict access" "fieldset"
    And ".availability-children .availability-eye-disabled" "css_element" should be visible
    And ".availability-children .availability-eye" "css_element" should not be visible
    And I should not see "The eye must stay closed." in the "Restrict access" "fieldset"
    And I should not see "Choose the recovery round" in the "Restrict access" "fieldset"
    And I should not see "Round not available" in the "Restrict access" "fieldset"
    And I press "Save and return to course"
    And I should not see "needs the restriction \"Recovery round\""
    And the restrictions of "rec" should be only the recovery round "round1" hidden
    And I am on the "C1" "Course" page logged in as "ana"
    And I should see "Atividade alvo"
    And I am on the "C1" "Course" page logged in as "bruno"
    And I should not see "Atividade alvo"
    And I am on the "rec" "page activity" page
    And I should not see "Conteudo do alvo"
    And I am on the "C1" "Course" page logged in as "carla"
    And I should not see "Atividade alvo"

  @javascript
  Scenario: QJ2 - two rounds: the second one is chosen, saved and shown again when reopened; only its sent student
    Given the recovery activity of "Segunda rodada" is "Atividade alvo"
    And I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And I click on "Add restriction..." "button"
    And I click on "Recovery round" "button" in the "Add restriction..." "dialogue"
    And I set the field "Recovery round" to "Segunda rodada"
    And I press "Save and return to course"
    And the restrictions of "rec" should be only the recovery round "round2" hidden
    When I am on the "rec" "page activity editing" page
    And I expand all fieldsets
    Then the field "Recovery round" matches value "Segunda rodada"
    And ".availability-children .availability-eye-disabled" "css_element" should be visible
    And I should not see "Choose the recovery round" in the "Restrict access" "fieldset"
    And I press "Save and return to course"
    And the restrictions of "rec" should be only the recovery round "round2" hidden
    And I am on the "C1" "Course" page logged in as "carla"
    And I should see "Atividade alvo"
    And I am on the "C1" "Course" page logged in as "ana"
    And I should not see "Atividade alvo"
    And I am on the "C1" "Course" page logged in as "bruno"
    And I should not see "Atividade alvo"

  @javascript
  Scenario: QJ3 - another condition joined with "all of": every eye closed, accepted, still only the sent student
    Given I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And I click on "Add restriction..." "button"
    And I click on "Recovery round" "button" in the "Add restriction..." "dialogue"
    And I click on "Add restriction..." "button"
    And I click on "Date" "button" in the "Add restriction..." "dialogue"
    And I set the field "direction" to "from"
    And I set the field "x[year]" to "2020"
    Then ".availability-children .availability-eye" "css_element" should not be visible
    And I should not see "The eye must stay closed." in the "Restrict access" "fieldset"
    And I press "Save and return to course"
    And the restrictions of "rec" should join 2 conditions with the recovery round "round1" all hidden
    And I am on the "C1" "Course" page logged in as "ana"
    And I should see "Atividade alvo"
    And I am on the "C1" "Course" page logged in as "bruno"
    And I should not see "Atividade alvo"

  @javascript
  Scenario: QJ4 - clicking the closed eye changes nothing
    Given the activity "rec" is restricted by the recovery round "round1" and hidden
    And I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    When I click on ".availability-children .availability-eye-disabled" "css_element"
    Then ".availability-children .availability-eye-disabled" "css_element" should be visible
    And ".availability-children .availability-eye" "css_element" should not be visible
    And I press "Save and return to course"
    And the restrictions of "rec" should be only the recovery round "round1" hidden

  @javascript
  Scenario: QJ5 - trash bin: replaced by another condition is refused; removed and added again is accepted
    Given the activity "rec" is restricted by the recovery round "round1" and hidden
    And I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    When I click on ".availability-item .availability-delete img" "css_element"
    And I click on "Add restriction..." "button"
    And I click on "Date" "button" in the "Add restriction..." "dialogue"
    And I press "Save and return to course"
    Then I should see "needs the restriction \"Recovery round\""
    And the restrictions of "rec" should be only the recovery round "round1" hidden
    And I am on the "rec" "page activity editing" page
    And I expand all fieldsets
    And I click on ".availability-item .availability-delete img" "css_element"
    And I click on "Add restriction..." "button"
    And I click on "Recovery round" "button" in the "Add restriction..." "dialogue"
    And I press "Save and return to course"
    And I should not see "needs the restriction \"Recovery round\""
    And the restrictions of "rec" should be only the recovery round "round1" hidden
    And I am on the "C1" "Course" page logged in as "bruno"
    And I should not see "Atividade alvo"

  @javascript
  Scenario: QJ6 - the condition plugin switched off: the editor no longer offers it
    Given the availability condition "recuperacao" is disabled
    And I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    When I click on "Add restriction..." "button"
    Then "Date" "button" should exist in the "Add restriction..." "dialogue"
    And "Recovery round" "button" should not exist in the "Add restriction..." "dialogue"

  @javascript
  Scenario: QJ7 - Brazilian Portuguese: removal and restriction set are refused with Portuguese messages
    Given the activity "rec" is restricted by the recovery round "round1" and hidden
    And I am on the "rec" "page activity editing" page logged in as "profpt"
    And I expand all fieldsets
    And I should see "Rodada do alvo" in the "Restringir acesso" "fieldset"
    When I click on ".availability-item .availability-delete img" "css_element"
    And I press "Salvar e voltar ao curso"
    Then I should see "precisa da restrição \"Rodada de recuperação\""
    And the restrictions of "rec" should be only the recovery round "round1" hidden
    And I am on the "rec" "page activity editing" page
    And I expand all fieldsets
    And I click on "Adicionar restrição..." "button"
    And I click on "Grupo de restrição" "button" in the "Adicionar restrição..." "dialogue"
    And I click on "Adicionar restrição..." "button" in the ".availability-children .availability-list" "css_element"
    And I click on "Rodada de recuperação" "button" in the "Adicionar restrição..." "dialogue"
    And I should see "Tire-a do grupo de restrição." in the ".availability-children .availability-list" "css_element"
    And I press "Salvar e voltar ao curso"
    And I should see "deve ficar no nível principal"
    And the restrictions of "rec" should be only the recovery round "round1" hidden
    And I am on "Curso 1" course homepage
    And I should see "Você é aluno da rodada de recuperação" in the "Atividade alvo" "activity"
    And I should not see "Ana Almeida"

  Scenario: Q8 - a non-editing teacher (no Manage activities) sees the restriction text, no student, no editor
    Given the activity "rec" is restricted by the recovery round "round1" and hidden
    When I am on the "C1" "Course" page logged in as "teacher2"
    Then I should see "You are a student of the recovery round" in the "Atividade alvo" "activity"
    And I should not see "Ana Almeida"
    And I should not see "Rodada do alvo" in the "Atividade alvo" "activity"
    And "Edit settings" "link" should not exist in the "Atividade alvo" "activity"

  Scenario: Q9 - restriction removed outside the form (direct write, no event): the guard task hides the activity (condition_missing) from everyone
    Given the activity "rec" is restricted by the recovery round "round1" and hidden
    And the recovery guard of "rec" should be "ok"
    When the access restrictions of the recovery activity of "Rodada do alvo" are removed
    And I run the scheduled task "\mod_recuperacao\task\guard_targets"
    Then the recovery guard of "rec" should be "engaged:condition_missing"
    And I am on the "C1" "Course" page logged in as "bruno"
    And I should not see "Atividade alvo"
    And I am on the "rec" "page activity" page
    And I should not see "Conteudo do alvo"
    And I am on the "C1" "Course" page logged in as "ana"
    And I should not see "Atividade alvo"
