# Turkish Textbook compact lesson header hotfix

This maintenance branch is based on v6.7.32, the exact523-file production
baseline independently verified on turkishtextbook.com on November3,2026.
Version6.7.36 is a scoped site hotfix, delivered as a packaged GitHub
**prerelease** through WordPress's native plugin upgrader. It is not a stable
promotion of main or dev. The site's main update channel remains unchanged.
Do not mark this package as the global Latest stable release: RELEASING.md's
dev/main tree-equivalence requirement remains in effect for stable promotion.

Runtime changes are limited to the version header/constant, the content-lesson
prerequisite renderer and its stylesheet. Main6.7.34's other changes are not
included. The same layout fix is maintained on dev for inclusion in a future
ordinary stable promotion; this backport avoids an unrelated development
upgrade for a mobile presentation repair.

Top prerequisites render nothing forzero, a compact linked row forone, and
closed native details/summary for two or more. Every link and completion state
is retained. Mobile labels omit only balanced trailing parenthetical examples
when meaningful title text remains; full names are retained in accessible
labels/tooltips and desktop text. Stored titles and prerequisite relationships
are unchanged. Footer Continue learning cards keep their original renderer.

Article phones use smaller header typography and spacing, omit the redundant
article badge, and retain readable16px article text and44px interactive targets.
The first nested heading/paragraph margin is reset so a content wrapper cannot
reintroduce a large blank area at the start. Later lesson spacing is preserved.
Version6.7.36 also resets prerequisite-heading padding inherited from the theme
and gives phone article paragraphs/tables/cells a16px minimum independent of
small root-rem styles. Lesson heading sizes are preserved. The browser fixture
includes40px theme h2 padding and a14.592px root font to guard both regressions.

Focused validation: v6.7.32-based PHP suite21tests/195assertions;11Chromium
checks covering0/1/8 prerequisites at320/390/430px, keyboard disclosure,
full accessible names, completion styling and desktop/footer behavior.
Live theme, anonymous/mobile and actual descendant text sizes are separately
verified in the Websites workspace deployment receipt.
