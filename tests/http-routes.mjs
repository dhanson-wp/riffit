import assert from 'node:assert/strict';
const origin=process.env.RIFFIT_TEST_ORIGIN || 'http://localhost:3100';
if (!/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(origin)) throw new Error('This fixture check only runs against local Riffit.');
let checks=0;
async function page(path,status){const response=await fetch(origin+path);const html=await response.text();assert.equal(response.status,status,path+' status');assert.match(response.headers.get('content-type')||'',/text\/html/);if(status===200)assert.equal((html.match(/<h1(?:\s|>)/g)||[]).length,1,path+' has one page heading');checks++;return html;}
const home=await page('/',200);assert.match(home,/Skip to content/);
const feed=await (await fetch(origin+'/api/community/posts?sort=new')).json();
assert.ok(feed.items?.length,'Start the disposable WordPress fixture with at least one post.');
const post=feed.items[0];await page('/posts/'+post.id,200);
const community=await (await fetch(origin+'/api/community/communities/'+post.community_id)).json();
await page('/communities/'+community.slug,200);
for (const path of ['/posts/not-an-id','/posts/0','/posts/9007199254740993','/posts/99999999','/communities/does-not-exist','/communities/INVALID','/?post=bogus','/?community=-1']) {const html=await page(path,404);assert.match(html,/This page isn’t available/);}
const native=await fetch(origin+'/auth/wordpress?state=admin-'+ 'a'.repeat(64)+'&code=local-test-code',{redirect:'manual'});assert.equal(native.status,307);const handoff=new URL(native.headers.get('location'));assert.equal(handoff.pathname,'/wp-admin/admin-post.php');assert.equal(handoff.searchParams.get('action'),'riffit_wordpress_complete');assert.equal(native.headers.get('referrer-policy'),'no-referrer');checks++;
console.log(`Riffit HTTP route checks: ${checks} pages passed (valid pages, missing entities, malformed IDs, and one H1 on valid server-rendered pages).`);
