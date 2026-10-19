@availability @availability_recuperacao @mod_recuperacao
Feature: Recovery activity guard (M1.5) - the activity closes when its restriction stops working
  In order to keep the recovery activity private when the restriction is off
  As an administrator or a teacher
  I need the recovery activity hidden from students until the restriction works again

  Background:
    Given the following "courses" exist:
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
      | activity | name                 | course | idnumber | content             |
      | page     | Atividade guardada   | C1     | rec      | Conteudo guardado   |
    And the following "activities" exist:
      | activity    | name           | course | idnumber |
      | recuperacao | Rodada guardada | C1     | round1   |
    And the recovery activity of "Rodada guardada" is "Atividade guardada"
    And the following "mod_recuperacao > cases" exist:
      | round  | user  | status     |
      | round1 | ana   | sent       |
      | round1 | bruno | identified |
    And the activity "rec" is restricted by the recovery round "round1" and hidden

  Scenario: B1 - disabling the condition hides the activity from every student, enabling it gives access back
    Given the availability condition "recuperacao" is disabled
    When I am on the "C1" "Course" page logged in as "ana"
    Then I should not see "Atividade guardada"
    And I am on the "rec" "page activity" page
    And I should see "Sorry, this activity is currently hidden"
    And I should not see "Conteudo guardado"
    And I am on the "C1" "Course" page logged in as "bruno"
    And I should not see "Atividade guardada"
    And I am on the "rec" "page activity" page
    And I should not see "Conteudo guardado"
    And I am on the "Rodada guardada" "recuperacao activity" page logged in as "teacher1"
    And I should see "Activity hidden: action needed" in the ".alert-danger .alert-heading" "css_element"
    And I should see "The recovery activity is hidden from students." in the ".alert-danger" "css_element"
    And I should see "Reason: the restriction used by the round is disabled on this site."
    And ".alert-danger[role='alert']" "css_element" should not exist
    And I am on the "rec" "page activity" page
    And I should see "Conteudo guardado"
    And the availability condition "recuperacao" is enabled
    And I am on the "C1" "Course" page logged in as "ana"
    And I should see "Atividade guardada"
    And I am on the "rec" "page activity" page
    And I should see "Conteudo guardado"
    And I am on the "C1" "Course" page logged in as "bruno"
    And I should not see "Atividade guardada"
    And I am on the "Rodada guardada" "recuperacao activity" page logged in as "teacher1"
    And I should not see "The recovery activity is hidden from students."
    And I should not see "Activity hidden: action needed"

  Scenario: B2 - the server refuses a restriction tree that would open the recovery activity
    Given I am on the "rec" "page activity editing" page logged in as "teacher1"
    When I set the restrictions field to an or tree of the recovery round "round1"
    And I press "Save and return to course"
    Then I should see "must be at the top level, joined with"
    And I am on the "C1" "Course" page logged in as "bruno"
    And I should not see "Atividade guardada"

  # SEC-M1Z-01 (ADR-036): the core sends a stealth activity, name and link, to every student; the guard hides it.
  Scenario: B3 - made available but not shown on the course page, the activity is hidden and the teacher is told
    Given the following config values are set as admin:
      | allowstealth | 1 |
    And I am on the "rec" "page activity editing" page logged in as "teacher1"
    When I set the field "Availability" to "Make available but don't show on course page"
    And I press "Save and return to course"
    Then I should see "\"Atividade guardada\" was hidden from students: it is a recovery activity"
    And I am on the "Rodada guardada" "recuperacao activity" page
    And I should see "Activity hidden: action needed" in the ".alert-danger .alert-heading" "css_element"
    And I should see "Reason: the activity is available but not shown on the course page" in the ".alert-danger" "css_element"
    And I should see "show the activity on the course page, in a section that is shown" in the ".alert-danger" "css_element"
    And I am on the "rec" "page activity" page logged in as "ana"
    And I should not see "Conteudo guardado"
    And I am on the "rec" "page activity editing" page logged in as "teacher1"
    And I set the field "Availability" to "Show on course page"
    And I press "Save and return to course"
    And I should not see "was hidden from students"
    And I am on the "Rodada guardada" "recuperacao activity" page
    And I should not see "The recovery activity is hidden from students."
    And I am on the "rec" "page activity" page logged in as "ana"
    And I should see "Conteudo guardado"
    And I am on the "C1" "Course" page logged in as "bruno"
    And I should not see "Atividade guardada"
