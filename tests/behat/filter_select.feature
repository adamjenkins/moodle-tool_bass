@tool @tool_bass @javascript
Feature: Choose an activity type, filter its activities by name and select the shown ones
  In order to pick the activities to change in a long course
  As a teacher
  I need an activity type choice, a name filter, and a select-all that acts on the activities the filter shows

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format | summary |
      | Course 1 | C1        | topics |         |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | name           | course | idnumber |
      | assign   | Algebra essay  | C1     | a1       |
      | assign   | Algebra report | C1     | a2       |
      | assign   | Geometry essay | C1     | a3       |
      | page     | Reading        | C1     | p1       |
    And I log in as "teacher1"

  Scenario: Choose the type, then select all acts on the shown rows only and hidden rows keep their ticks
    Given I am on the "C1" "tool_bass > Bulk settings" page
    And "Algebra essay" "table_row" should not exist
    When I set the field "Activity type" to "Assignment (3)"
    Then I should see "Algebra essay" in the "tool_bass_table" "table"
    And I should not see "Reading" in the "tool_bass_table" "table"
    When I set the field "Filter by name" to "ALG"
    Then "Algebra essay" "table_row" should be visible
    And "Algebra report" "table_row" should be visible
    And "Geometry essay" "table_row" should not be visible
    When I click on "Select all shown activities" "checkbox"
    Then the field "Select Algebra essay" matches value "1"
    And the field "Select Algebra report" matches value "1"
    And the field "Select Geometry essay" matches value "0"
    When I set the field "Filter by name" to "report"
    And I click on "Select all shown activities" "checkbox"
    And I set the field "Filter by name" to ""
    Then the field "Select Algebra essay" matches value "1"
    And the field "Select Algebra report" matches value "0"
    And the field "Select Geometry essay" matches value "0"
    When I set the field "Filter by name" to "zzz"
    Then I should see "No activities match the filter."

  Scenario: Set the grade to pass as a percentage of each assignment's maximum grade
    Given the following "activities" exist:
      | activity | name        | course | idnumber | grade |
      | assign   | Short essay | C1     | a4       | 20    |
    And I am on the "C1:assign" "tool_bass > Bulk settings" page
    When I set the field "Select Algebra essay" to "1"
    And I set the field "Select Short essay" to "1"
    And I expand all fieldsets
    And I set the field "Change: Grade to pass" to "1"
    And I set the field "Grade to pass entered as" to "Percentage of each activity's maximum grade"
    # By id: the label "Grade to pass" also matches "Change: Grade to pass".
    And I set the field "id_gradepass" to "60"
    And I press "Preview"
    Then I should see "Activities that will change: 2."
    And I press "Apply changes"
    And I should see "Activities changed: 2."
    And the "gradepass" of activity "a1" should be "60"
    And the "gradepass" of activity "a4" should be "12"
    And the "gradepass" of activity "a2" should be "0"
