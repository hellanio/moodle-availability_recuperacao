@availability @availability_recuperacao @mod_recuperacao @qa_m1z2
Feature: QA M1.Z2 - stealth recovery activity in the browser course index (SEC-M1Z-01, ADR-036)
  In order to keep the recovery activity private
  As a student who was not sent
  I never get the stealth recovery activity in the course index, the course page or by its link

  Background:
    Given the following config values are set as admin:
      | allowstealth | 1 |
    And the following "courses" exist:
      | fullname | shortname |
      | Curso 1  | C1        |
    And the following "users" exist:
      | username | firstname | lastname | email             |
      | teacher1 | Teacher   | One      | t1@example.com    |
      | ana      | Ana       | Almeida  | ana@example.com   |
      | bruno    | Bruno     | Barbosa  | bruno@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | ana      | C1     | student        |
      | bruno    | C1     | student        |
    And the following "activities" exist:
      | activity | name               | course | idnumber | content           |
      | page     | Atividade guardada | C1     | rec      | Conteudo guardado |
    And the following "activities" exist:
      | activity    | name            | course | idnumber |
      | recuperacao | Rodada guardada | C1     | round1   |
    And the recovery activity of "Rodada guardada" is "Atividade guardada"
    And the following "mod_recuperacao > cases" exist:
      | round  | user  | status     |
      | round1 | ana   | sent       |
      | round1 | bruno | identified |
    And the activity "rec" is restricted by the recovery round "round1" and hidden

  @javascript
  Scenario: QZ1 - made stealth, nobody gets it in the course index; shown again, only the sent student
    Given I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    When I set the field "Availability" to "Make available but don't show on course page"
    And I press "Save and return to course"
    Then I should see "was hidden from students: it is a recovery activity"
    And I clear the browser session storage
    And I am on the "C1" "Course" page logged in as "bruno"
    And "Rodada guardada" "link" should exist in the "#courseindex" "css_element"
    And "Atividade guardada" "link" should not exist in the "#courseindex" "css_element"
    And I should not see "Atividade guardada" in the "region-main" "region"
    And I am on the "rec" "page activity" page
    And I should not see "Conteudo guardado"
    And I clear the browser session storage
    And I am on the "C1" "Course" page logged in as "ana"
    And "Rodada guardada" "link" should exist in the "#courseindex" "css_element"
    And "Atividade guardada" "link" should not exist in the "#courseindex" "css_element"
    And I am on the "rec" "page activity" page
    And I should not see "Conteudo guardado"
    And I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I expand all fieldsets
    And I set the field "Availability" to "Show on course page"
    And I press "Save and return to course"
    And I clear the browser session storage
    And I am on the "C1" "Course" page logged in as "ana"
    And "Atividade guardada" "link" should exist in the "#courseindex" "css_element"
    And I am on the "rec" "page activity" page
    And I should see "Conteudo guardado"
    And I clear the browser session storage
    And I am on the "C1" "Course" page logged in as "bruno"
    And "Rodada guardada" "link" should exist in the "#courseindex" "css_element"
    And "Atividade guardada" "link" should not exist in the "#courseindex" "css_element"
    And I am on the "rec" "page activity" page
    And I should not see "Conteudo guardado"
