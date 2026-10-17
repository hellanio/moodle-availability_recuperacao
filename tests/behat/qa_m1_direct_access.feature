@availability @availability_recuperacao
Feature: QA M1 - recovery activity invisible in the course and refused by direct URL
  In order to keep the recovery activity private
  As a student not sent to recovery
  I must neither see the activity nor open it by its URL

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Curso 1  | C1        |
    And the following "users" exist:
      | username | firstname | lastname | email             |
      | teacher1 | Teacher   | One      | t1@example.com    |
      | ana      | Ana       | Almeida  | ana@example.com   |
      | bruno    | Bruno     | Barbosa  | bruno@example.com |
      | carla    | Carla     | Cardoso  | carla@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | ana      | C1     | student        |
      | bruno    | C1     | student        |
      | carla    | C1     | student        |
    And the following "activities" exist:
      | activity | name                 | course | idnumber | content             |
      | page     | Atividade secreta QA | C1     | rec      | Conteudo secreto QA |
    And the following "activities" exist:
      | activity    | name             | course | idnumber |
      | recuperacao | Rodada secreta QA | C1     | round1   |
    And the recovery activity of "Rodada secreta QA" is "Atividade secreta QA"
    And the following "mod_recuperacao > cases" exist:
      | round  | user  | status     |
      | round1 | ana   | sent       |
      | round1 | bruno | identified |
    And the activity "rec" is restricted by the recovery round "round1" and hidden

  Scenario Outline: A student not sent neither sees nor opens the recovery activity
    Given I am on the "C1" "Course" page logged in as "<user>"
    Then I should not see "Atividade secreta QA"
    And "a[href*='/mod/page/view.php']" "css_element" should not exist
    When I am on the "rec" "page activity" page
    Then I should see "Sorry, this activity is currently hidden"
    And I should not see "Conteudo secreto QA"
    And I should not see "Atividade secreta QA"

    Examples:
      | user  |
      | bruno |
      | carla |

  Scenario: The sent student sees and opens the recovery activity
    Given I am on the "C1" "Course" page logged in as "ana"
    Then I should see "Atividade secreta QA"
    When I am on the "rec" "page activity" page
    Then I should see "Conteudo secreto QA"

  Scenario: The teacher sees and opens the recovery activity
    Given I am on the "C1" "Course" page logged in as "teacher1"
    Then I should see "Atividade secreta QA"
    When I am on the "rec" "page activity" page
    Then I should see "Conteudo secreto QA"
