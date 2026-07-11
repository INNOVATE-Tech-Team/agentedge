<?php
require_once __DIR__ . '/local_db.php';
$db = local_db();
$db->exec("DELETE FROM uni_lessons WHERE course_id IN (SELECT id FROM uni_courses WHERE title='Workshop Wednesdays')");
$db->exec("DELETE FROM uni_courses WHERE title='Workshop Wednesdays'");
$db->exec("DELETE FROM uni_categories WHERE name='Workshop Wednesdays'");
$db->prepare("INSERT INTO uni_categories (name,icon,sort_ord) VALUES (?,?,?)")->execute(["Workshop Wednesdays","🎓",20]);
$catId=(int)$db->lastInsertId();
$db->prepare("INSERT INTO uni_courses (category_id,title,description,is_required,sort_ord,published,created_by) VALUES (?,?,?,?,?,?,?)")->execute([$catId,"Workshop Wednesdays","Weekly workshops covering real estate tools, strategies, and industry topics.",0,10,1,"darren@innovateonline.com"]);
$courseId=(int)$db->lastInsertId();
$lessons=[["June 19th 2024 - 1031 Exchange","https://youtu.be/q_WVpdlCalk"],["June 26th 2024 - Closing Costs App Launch","https://youtu.be/iOAJ8OtW4mM"],["July 10th 2024 - Canva with Lisa","https://youtu.be/xjR7rBO9xdY"],["July 31st 2024 - Probate and Real Estate Closings","https://youtu.be/OIqT_8BhJKw"],["Oct 16th 2024 - Your Brand in a Post NAR Settlement","https://youtu.be/aP1nXvuKJ8A"],["Oct 23rd 2024 - Mega and Broker Open Houses","https://youtu.be/qZONwovL2og"],["Oct 30th 2024 - Your Social Media with Juliann","https://youtu.be/fCzkjdKEw84"]];
$stmt=$db->prepare("INSERT INTO uni_lessons (course_id,title,sort_ord,type,embed_url,content_html,duration_sec) VALUES (?,?,?,?,?,'',0)");
foreach($lessons as $i=>$l){$stmt->execute([$courseId,$l[0],($i+1)*10,"video",$l[1]]);}
echo "Done! cat=$catId course=$courseId lessons=".count($lessons)."\n";
