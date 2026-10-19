@availability @availability_recuperacao @qa_m14c_l2
Feature: QA M1.4c loop 2 - the error badge of the restriction "Recovery round" and the note of the locked eye
  In order to trust the corrections UX-4C-02, UX-4C-03 and UX-4C-04
  As the independent QA
  I need the badge of the item to show only the error of the current check, the note of the locked eye to be
  visible and to describe the list of rounds, and the server to explain how to put the restriction back

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
      | activity | name           | course | idnumber | content          | section |
      | page     | Atividade alvo | C1     | rec      | Conteudo do alvo | 1       |
    And the following "activities" exist:
      | activity    | name           | course | idnumber |
      | recuperacao | Rodada do alvo | C1     | round1   |
      | recuperacao | Segunda rodada | C1     | round2   |
    And the recovery activity of "Rodada do alvo" is "Atividade alvo"
    And the recovery activity of "Segunda rodada" is "Atividade alvo"
    And the following "mod_recuperacao > cases" exist:
      | round  | user  | status     |
      | round1 | ana   | sent       |
      | round1 | bruno | identified |

  @javascript
  Scenario: QL1 (UX-4C-02) - the badge always holds only the error of the last check, and is empty when nothing is wrong
    Given I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    When I click on "Add restriction..." "button"
    And I click on "Recovery round" "button" in the "Add restriction..." "dialogue"
    Then "//div[contains(@class,'availability-item')]/span[contains(@class,'badge')][normalize-space(.)='Choose the recovery round.']" "xpath_element" should exist
    And I set the field "Restriction type" to "must not"
    And "//div[contains(@class,'availability-item')]/span[contains(@class,'badge')][normalize-space(.)='Choose the recovery round.']" "xpath_element" should exist
    And I set the field "Recovery round" to "Rodada do alvo"
    And "//div[contains(@class,'availability-item')]/span[contains(@class,'badge')][normalize-space(.)='Use \"must\" and \"all\" at the top.']" "xpath_element" should exist
    And I set the field "Restriction type" to "must"
    And "//div[contains(@class,'availability-item')]/span[contains(@class,'badge')][normalize-space(.)!='']" "xpath_element" should not exist
    And I set the field "Restriction type" to "must not"
    And I set the field "Restriction type" to "must"
    And I set the field "Restriction type" to "must not"
    And "//div[contains(@class,'availability-item')]/span[contains(@class,'badge')][normalize-space(.)='Use \"must\" and \"all\" at the top.']" "xpath_element" should exist
    And I set the field "Restriction type" to "must"
    And "//div[contains(@class,'availability-item')]/span[contains(@class,'badge')][normalize-space(.)!='']" "xpath_element" should not exist
    And I press "Save and return to course"
    And the restrictions of "rec" should be only the recovery round "round1" hidden
    And I am on the "C1" "Course" page logged in as "bruno"
    And I should not see "Atividade alvo"
    And I should not see "You are a student of the recovery round"

  @javascript @accessibility
  Scenario: QL2 (UX-4C-04) - the note of the locked eye is visible text and describes the list of rounds
    Given I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    When I click on "Add restriction..." "button"
    And I click on "Recovery round" "button" in the "Add restriction..." "dialogue"
    Then I should see "Always hidden entirely from students without access, so the eye stays closed." in the "Restrict access" "fieldset"
    And ".availability_recuperacao .availability-recuperacao-hiddennote" "css_element" should be visible
    And "//select[@name='id'][@aria-describedby = //small[contains(@class,'availability-recuperacao-hiddennote')]/@id]" "xpath_element" should exist
    And ".availability-children .availability-eye-disabled" "css_element" should be visible
    And the "Restrict access" "fieldset" should meet "wcag2a,wcag2aa,wcag21a,wcag21aa,wcag22aa" accessibility standards

  @javascript
  Scenario: QL3 (UX-4C-04, pt_BR) - with one round, the note is shown next to the name of the round
    Given the following "activities" exist:
      | activity | name      | course | idnumber | content    | section |
      | page     | Alvo dois | C1     | rec2     | Conteudo 2 | 1       |
    And the recovery activity of "Segunda rodada" is "Alvo dois"
    And I am on the "rec" "page activity editing" page logged in as "profpt"
    And I expand all fieldsets
    When I click on "Adicionar restrição..." "button"
    And I click on "Rodada de recuperação" "button" in the "Adicionar restrição..." "dialogue"
    Then I should see "Rodada do alvo" in the "Restringir acesso" "fieldset"
    And "//div[contains(@class,'availability_recuperacao')]//select[@name='id']" "xpath_element" should not exist
    And I should see "Sempre oculta por completo para os alunos sem acesso, por isso o olho fica fechado." in the "Restringir acesso" "fieldset"
    And ".availability-children .availability-eye-disabled" "css_element" should be visible

  @javascript
  Scenario: QL4 (UX-4C-03) - the server explains how to put the restriction back, in English and in Portuguese
    Given the activity "rec" is restricted by the recovery round "round1" and hidden
    And I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    When I click on ".availability-item .availability-delete img" "css_element"
    And I press "Save and return to course"
    Then I should see "Under Restrict access, choose Add restriction..., then Recovery round, and save again."
    And the restrictions of "rec" should be only the recovery round "round1" hidden
    And I am on the "rec" "page activity editing" page logged in as "profpt"
    And I expand all fieldsets
    And I click on ".availability-item .availability-delete img" "css_element"
    And I press "Salvar e voltar ao curso"
    And I should see "Em Restringir acesso, escolha Adicionar restrição..., depois Rodada de recuperação, e salve de novo."
    And the restrictions of "rec" should be only the recovery round "round1" hidden
    And I am on the "C1" "Course" page logged in as "bruno"
    And I should not see "Atividade alvo"
