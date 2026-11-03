const test=require('node:test');const assert=require('node:assert/strict');
const R=require('../game/rules');
test('deck has 32 unique cards',()=>{const d=R.makeDeck();assert.equal(d.length,32);assert.equal(new Set(d.map(c=>c.id)).size,32);});
test('trump order gives jack then nine then ace',()=>{assert.ok(R.rankValue({suit:'S',rank:'J'},'S')>R.rankValue({suit:'S',rank:'9'},'S'));assert.ok(R.rankValue({suit:'S',rank:'9'},'S')>R.rankValue({suit:'S',rank:'A'},'S'));});
test('sun order gives ace above ten',()=>{assert.ok(R.rankValue({suit:'H',rank:'A'},null)>R.rankValue({suit:'H',rank:'10'},null));});
test('dealing gives five cards each and an exposed card',()=>{const g=R.createGame();R.deal(g);assert.deepEqual(g.hands.map(h=>h.length),[5,5,5,5]);assert.ok(g.upCard);assert.equal(g.phase,'auction1');});
test('follow suit is mandatory',()=>{const hand=[{id:'HA',suit:'H',rank:'A'},{id:'S7',suit:'S',rank:'7'}];const trick=[{seat:0,card:{id:'HK',suit:'H',rank:'K'}}];assert.deepEqual(R.legalCards(hand,trick,{type:'sun',trump:null}).map(c=>c.id),['HA']);});
test('void player must trump when opponents currently win in hokum',()=>{const hand=[{id:'SJ',suit:'S',rank:'J'},{id:'D7',suit:'D',rank:'7'}];const trick=[{seat:0,card:{id:'HA',suit:'H',rank:'A'}}];assert.deepEqual(R.legalCards(hand,trick,{type:'hokum',trump:'S'},1).map(c=>c.id),['SJ']);});
