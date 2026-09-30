-- restore the randomization trigger logic on pid 271 as it was before apply-rand-trigger-tsr-complete.php
UPDATE redcap_randomization SET trigger_logic = '[calc_screen_result]=1' WHERE project_id = 271 AND rid = 8 LIMIT 1;
