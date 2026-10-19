@availability @availability_recuperacao @mod_recuperacao
Feature: Editor of the restriction "Recovery round" (M1.4c)
  In order to protect the recovery activity of a round without editing raw restrictions
  As a teacher
  I need to add the restriction "Recovery round" only to that activity, always hidden from who does not meet it

  Background:
    Given the following "courses" exist:
      | fullname | shortname | format | numsections |
      | Curso 1  | C1        | topics | 2           |
    And the following "users" exist:
      | username | firstname | lastname | email             | lang  |
      | teacher1 | Teacher   | One      | t1@example.com    | en    |
      | profpt   | Prof      | Pt       | pt@example.com    | pt_br |
      | ana      | Ana       | Almeida  | ana@example.com   | en    |
      | bruno    | Bruno     | Barbosa  | bruno@example.com | en    |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | profpt   | C1     | editingteacher |
      | ana      | C1     | student        |
      | bruno    | C1     | student        |
    And the following "activities" exist:
      | activity | name               | course | idnumber | content          | section |
      | page     | Atividade alvo     | C1     | rec      | Conteudo do alvo | 1       |
      | page     | Atividade livre    | C1     | free     | Conteudo livre   | 1       |
    And the following "activities" exist:
      | activity    | name           | course | idnumber |
      | recuperacao | Rodada do alvo | C1     | round1   |
    And the recovery activity of "Rodada do alvo" is "Atividade alvo"
    And the following "mod_recuperacao > cases" exist:
      | round  | user  | status     |
      | round1 | ana   | sent       |
      | round1 | bruno | identified |

  @javascript
  Scenario: J1 - the restriction is offered only in the recovery activity of a round
    Given I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    When I click on "Add restriction..." "button"
    Then "Recovery round" "button" should exist in the "Add restriction..." "dialogue"
    And I click on "Cancel" "button" in the "Add restriction..." "dialogue"
    And I am on the "free" "page activity editing" page
    And I expand all fieldsets
    And I click on "Add restriction..." "button"
    And "Date" "button" should exist in the "Add restriction..." "dialogue"
    And "Recovery round" "button" should not exist in the "Add restriction..." "dialogue"
    And I click on "Cancel" "button" in the "Add restriction..." "dialogue"
    And I am on "Curso 1" course homepage with editing mode on
    And I edit the section "1"
    And I expand all fieldsets
    And I click on "Add restriction..." "button"
    And "Date" "button" should exist in the "Add restriction..." "dialogue"
    And "Recovery round" "button" should not exist in the "Add restriction..." "dialogue"

  @javascript
  Scenario: J2 - adding the restriction closes the eye and saves the tree the server expects
    Given I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    When I click on "Add restriction..." "button"
    And I click on "Recovery round" "button" in the "Add restriction..." "dialogue"
    Then I should see "Rodada do alvo" in the "Restrict access" "fieldset"
    And ".availability-children .availability-eye-disabled" "css_element" should be visible
    And ".availability-children .availability-eye" "css_element" should not be visible
    And I should not see "Choose the recovery round" in the "Restrict access" "fieldset"
    And I should not see "The eye must stay closed." in the "Restrict access" "fieldset"
    And I should see "Always hidden entirely from students without access, so the eye stays closed." in the "Restrict access" "fieldset"
    And I press "Save and return to course"
    And the restrictions of "rec" should be only the recovery round "round1" hidden
    And I am on the "C1" "Course" page logged in as "ana"
    And I should see "Atividade alvo"
    And I am on the "rec" "page activity" page
    And I should see "Conteudo do alvo"
    And I am on the "C1" "Course" page logged in as "bruno"
    And I should not see "Atividade alvo"
    And I am on the "rec" "page activity" page
    And I should not see "Conteudo do alvo"

  @javascript
  Scenario: J3 - "must not", "any" and a restriction set are pointed out and not saved
    Given the activity "rec" is restricted by the recovery round "round1" and hidden
    And I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And I should see "Rodada do alvo" in the "Restrict access" "fieldset"
    And ".availability-children .availability-eye-disabled" "css_element" should be visible
    When I set the field "Restriction type" to "must not"
    Then I should see "Use \"must\" and \"all\" at the top." in the "Restrict access" "fieldset"
    And I press "Save and return to course"
    And I should see "must be at the top level, joined with \"must\" (all of)"
    And I should see "Use \"must\" and \"all\" at the top."
    And the restrictions of "rec" should be only the recovery round "round1" hidden
    And I am on the "rec" "page activity editing" page
    And I expand all fieldsets
    And I click on "Add restriction..." "button"
    And I click on "Date" "button" in the "Add restriction..." "dialogue"
    And I set the field "Required restrictions" to "any"
    And I should see "Use \"must\" and \"all\" at the top." in the "Restrict access" "fieldset"
    And I press "Save and return to course"
    And I should see "Use \"must\" and \"all\" at the top."
    And the restrictions of "rec" should be only the recovery round "round1" hidden
    And I am on the "rec" "page activity editing" page
    And I expand all fieldsets
    And I click on "Add restriction..." "button"
    And I click on "Restriction set" "button" in the "Add restriction..." "dialogue"
    And I click on "Add restriction..." "button" in the ".availability-children .availability-list" "css_element"
    And I click on "Recovery round" "button" in the "Add restriction..." "dialogue"
    And I should see "Move it out of the restriction set." in the ".availability-children .availability-list" "css_element"
    And I press "Save and return to course"
    And I should see "Move it out of the restriction set."
    And the restrictions of "rec" should be only the recovery round "round1" hidden
    And I am on the "C1" "Course" page logged in as "bruno"
    And I should not see "Atividade alvo"

  @javascript
  Scenario: J4 - deleting the restriction is refused by the server
    Given the activity "rec" is restricted by the recovery round "round1" and hidden
    And I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    When I click on ".availability-item .availability-delete img" "css_element"
    Then I should not see "Rodada do alvo" in the "Restrict access" "fieldset"
    And I press "Save and return to course"
    And I should see "needs the restriction \"Recovery round\""
    And the restrictions of "rec" should be only the recovery round "round1" hidden
    And I am on the "C1" "Course" page logged in as "bruno"
    And I should not see "Atividade alvo"

  @javascript
  Scenario: J2 with two rounds - the teacher must choose one of the rounds that use the activity
    Given the following "activities" exist:
      | activity    | name             | course | idnumber |
      | recuperacao | Segunda rodada   | C1     | round2   |
    And the recovery activity of "Segunda rodada" is "Atividade alvo"
    And I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    When I click on "Add restriction..." "button"
    And I click on "Recovery round" "button" in the "Add restriction..." "dialogue"
    Then I should see "Choose the recovery round" in the "Restrict access" "fieldset"
    And I press "Save and return to course"
    And I should see "Choose the recovery round"
    And I expand all fieldsets
    And I set the field "Recovery round" to "Rodada do alvo"
    And I should not see "Choose the recovery round" in the "Restrict access" "fieldset"
    And I press "Save and return to course"
    And the restrictions of "rec" should be only the recovery round "round1" hidden

  @javascript
  Scenario: J3 with two rounds - choosing the round after "must not" shows the error of the tree, not the stale one
    Given the following "activities" exist:
      | activity    | name             | course | idnumber |
      | recuperacao | Segunda rodada   | C1     | round2   |
    And the recovery activity of "Segunda rodada" is "Atividade alvo"
    And I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    When I click on "Add restriction..." "button"
    And I click on "Recovery round" "button" in the "Add restriction..." "dialogue"
    And I should see "Choose the recovery round." in the "Restrict access" "fieldset"
    And I set the field "Restriction type" to "must not"
    And I should see "Choose the recovery round." in the "Restrict access" "fieldset"
    And I set the field "Recovery round" to "Segunda rodada"
    Then I should see "Use \"must\" and \"all\" at the top." in the "Restrict access" "fieldset"
    And I should not see "Choose the recovery round." in the "Restrict access" "fieldset"
    And I set the field "Restriction type" to "must"
    And I should not see "Use \"must\" and \"all\" at the top." in the "Restrict access" "fieldset"
    And I press "Save and return to course"
    And the restrictions of "rec" should be only the recovery round "round2" hidden

  @javascript @accessibility
  Scenario: J6 - at 320 pixels, the error of the restriction wraps and the round is not cut (WCAG 1.4.10)
    Given the following "activities" exist:
      | activity    | name                                                        | course | idnumber |
      | recuperacao | Rodada de recuperacao do segundo bimestre de matematica II | C1     | round3   |
    And the recovery activity of "Rodada de recuperacao do segundo bimestre de matematica II" is "Atividade livre"
    And I change viewport size to "320x800"
    And I am on the "free" "page activity editing" page logged in as "profpt"
    And I expand all fieldsets
    When I click on "Adicionar restrição..." "button"
    And I click on "Rodada de recuperação" "button" in the "Adicionar restrição..." "dialogue"
    And I set the field "Tipo de restrição" to "não deve"
    Then I should see "Use \"deve\" e \"tudo\" no topo." in the "Restringir acesso" "fieldset"
    And I should see "Sempre oculta por completo para os alunos sem acesso, por isso o olho fica fechado." in the "Restringir acesso" "fieldset"
    And the restriction "Recovery round" should show its error within the window width
    And I set the field "Tipo de restrição" to "deve"
    And I click on "Adicionar restrição..." "button"
    And I click on "Grupo de restrição" "button" in the "Adicionar restrição..." "dialogue"
    And I click on "Adicionar restrição..." "button" in the ".availability-children .availability-list" "css_element"
    And I click on "Rodada de recuperação" "button" in the "Adicionar restrição..." "dialogue"
    And I should see "Tire-a do grupo de restrição." in the ".availability-children .availability-list" "css_element"
    And the restriction "Recovery round" should show its error within the window width
    And the "Restringir acesso" "fieldset" should meet "wcag2a,wcag2aa,wcag21a,wcag21aa,wcag22aa" accessibility standards

  @javascript @accessibility
  Scenario: J6b - at 320 pixels, a list of two rounds with long names fits the window (WCAG 1.4.10, UX-4C-14)
    Given the following "activities" exist:
      | activity    | name                                                               | course | idnumber |
      | recuperacao | Rodada de recuperacao do segundo bimestre de matematica II turma A | C1     | round3   |
      | recuperacao | Rodada de recuperacao do segundo bimestre de matematica II turma B | C1     | round4   |
    And the recovery activity of "Rodada de recuperacao do segundo bimestre de matematica II turma A" is "Atividade livre"
    And the recovery activity of "Rodada de recuperacao do segundo bimestre de matematica II turma B" is "Atividade livre"
    And I change viewport size to "320x800"
    And I am on the "free" "page activity editing" page logged in as "profpt"
    And I expand all fieldsets
    When I click on "Adicionar restrição..." "button"
    And I click on "Rodada de recuperação" "button" in the "Adicionar restrição..." "dialogue"
    Then I should see "Escolha a rodada" in the "Restringir acesso" "fieldset"
    And the restriction "Recovery round" should fit in the window width without horizontal scrolling
    And the "Restringir acesso" "fieldset" should meet "wcag2a,wcag2aa,wcag21a,wcag21aa,wcag22aa" accessibility standards

  @javascript @accessibility
  Scenario: J6c - at 320 pixels, an unavailable round in the list fits the window (WCAG 1.4.10, UX-4C-14)
    Given the activity "free" is restricted by the recovery round "round1" and hidden
    And I change viewport size to "320x800"
    And I am on the "free" "page activity editing" page logged in as "profpt"
    And I expand all fieldsets
    Then I should see "Rodada indisponível" in the "Restringir acesso" "fieldset"
    And the restriction "Recovery round" should fit in the window width without horizontal scrolling
    And the restriction "Recovery round" should show its error within the window width
    And the "Restringir acesso" "fieldset" should meet "wcag2a,wcag2aa,wcag21a,wcag21aa,wcag22aa" accessibility standards

  @javascript
  Scenario: J5 - a restriction naming a round that does not use the activity is shown as unavailable
    Given the activity "free" is restricted by the recovery round "round1" and hidden
    And I am on the "free" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    Then I should see "Round not available" in the "Restrict access" "fieldset"
    And I should see "This round does not use this activity: choose another." in the "Restrict access" "fieldset"
    And I press "Save and return to course"
    And I should see "This round does not use this activity: choose another."

  @javascript
  Scenario: J2/J3 in Brazilian Portuguese - the teacher adds the restriction and is warned about "must not"
    Given I am on the "rec" "page activity editing" page logged in as "profpt"
    And I expand all fieldsets
    When I click on "Adicionar restrição..." "button"
    And I click on "Rodada de recuperação" "button" in the "Adicionar restrição..." "dialogue"
    Then I should see "Rodada do alvo" in the "Restringir acesso" "fieldset"
    And ".availability-children .availability-eye-disabled" "css_element" should be visible
    And I set the field "Tipo de restrição" to "não deve"
    And I should see "Use \"deve\" e \"tudo\" no topo." in the "Restringir acesso" "fieldset"
    And I set the field "Tipo de restrição" to "deve"
    And I should not see "Use \"deve\" e \"tudo\" no topo." in the "Restringir acesso" "fieldset"
    And I press "Salvar e voltar ao curso"
    And the restrictions of "rec" should be only the recovery round "round1" hidden

  Scenario: B3 - without JavaScript, the tree the editor builds is saved and only the student with access opens the activity
    Given I am on the "rec" "page activity editing" page logged in as "teacher1"
    When I set the restrictions field to the editor tree of the recovery round "round1"
    And I press "Save and return to course"
    Then the restrictions of "rec" should be only the recovery round "round1" hidden
    And I am on the "C1" "Course" page logged in as "ana"
    And I should see "Atividade alvo"
    And I am on the "C1" "Course" page logged in as "bruno"
    And I should not see "Atividade alvo"
    And I am on the "rec" "page activity" page
    And I should not see "Conteudo do alvo"
