@tool @tool_bass
Feature: Only users with the bulk settings capability get the page
  In order to keep bulk changes to activity settings with the right people
  As an administrator
  I need the course link and the page to need tool/bass:manage

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
      | activity | name   | course | idnumber |
      | quiz     | Quiz 1 | C1     | q1       |

  Scenario: An editing teacher has the link and the page
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then "Bulk Activity Settings Selector" "link" should exist
    And I navigate to "Bulk Activity Settings Selector" in current page administration
    And I should see "Quiz (1)"

  Scenario: A teacher without the capability has no link and is refused the page
    Given the following "permission overrides" exist:
      | capability       | permission | role           | contextlevel | reference |
      | tool/bass:manage | Prohibit   | editingteacher | Course       | C1        |
    And I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then "Bulk Activity Settings Selector" "link" should not exist
    And I should be refused access to the bulk settings page of "C1"
