const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/commuter/assets/user.js', 'utf8');
const html = fs.readFileSync('public/commuter/user-login.html', 'utf8');

test('credential forms declare local POST destinations and contain no preview password form', () => {
  assert.match(html, /<form[^>]+id="passenger-login"[^>]+method="post"[^>]+action="api\/auth\.php\?action=login"/);
  assert.match(html, /<form[^>]+id="passenger-register"[^>]+method="post"[^>]+action="api\/auth\.php\?action=register"/);
  assert.doesNotMatch(html, /id="driver-login"|id="driver-password"|Driver sign-in preview/);
});

async function signIn(result, ok = true) {
  const nodes = new Map(), redirects = [], requests = [];
  for (const match of html.matchAll(/\bid="([^"]+)"/g)) nodes.set(match[1], {
    value:'', hidden:true, events:{}, addEventListener(event, fn) { this.events[event]=fn; },
    setAttribute() {}, focus() {}, reset() {},
  });
  nodes.get('email').value='account@example.invalid';nodes.get('password').value='Fixture password!';
  vm.runInNewContext(source, {
    document:{getElementById:id=>nodes.get(id)||null,querySelectorAll:()=>[],querySelector:()=>({textContent:''}),addEventListener(){}},
    window:{addEventListener(){},location:{assign:path=>redirects.push(path),replace:path=>redirects.push(path)}},
    fetch:async (url, options)=>{
      if(url.includes('action=session'))return {ok:false};
      requests.push({url,options});return {ok,json:async()=>result};
    }
  });
  await nodes.get('passenger-login').events.submit({preventDefault(){}});
  return {redirects,requests,nodes};
}
test('passenger login sends verified admins to the admin dashboard', async()=>{
  const app=await signIn({portal:'admin',account:null});
  assert.deepEqual(app.redirects,['../admin-dashboard.php']);
  assert.equal(app.nodes.get('password').value,'');
  assert.equal(app.requests[0].options.headers['Content-Type'],'application/json');
});
test('normal passengers still open their map', async()=>{
  const app=await signIn({portal:'commuter',account:{id:1}});
  assert.deepEqual(app.redirects,['user-map.html']);
});
test('failed admin login stays on the form with the server error', async()=>{
  const app=await signIn({error:'Too many sign-in attempts.'},false);
  assert.deepEqual(app.redirects,[]);
  assert.equal(app.nodes.get('login-error').textContent,'Too many sign-in attempts.');
  assert.equal(app.nodes.get('login-error').hidden,false);
});
