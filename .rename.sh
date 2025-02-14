find ./ -type f -print0 | xargs -0 perl -pi -e 's/Disciple_Tools_AI/Disciple_Tools_AI/g';
find ./ -type f -print0 | xargs -0 perl -pi -e 's/disciple_tools_ai/disciple_tools_ai/g';
find ./ -type f -print0 | xargs -0 perl -pi -e 's/disciple-tools-ai/disciple-tools-ai/g';
find ./ -type f -print0 | xargs -0 perl -pi -e 's/starter_post_type/starter_post_type/g';
find ./ -type f -print0 | xargs -0 perl -pi -e 's/Disciple Tools AI/Disciple Tools AI/g';
mv disciple-tools-ai.php disciple-tools-ai.php
rm .rename.sh
