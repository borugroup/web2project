-- Adds a project_allow_other_user_tasklogs field to the project table. This 
-- option if set will allow users that have add task access to a project to 
-- create task logs for other users the task is assigned to.

ALTER TABLE `projects` ADD COLUMN `project_allow_other_user_tasklogs` int(1) NOT NULL DEFAULT '0';
