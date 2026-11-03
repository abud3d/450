const test=require('node:test');
const assert=require('node:assert/strict');
const R=require('../game/rules');

function pendingPurchase(order,buyer,stage='responses'){
 const game=R.createGame();R.deal(game);game.auctionOrder=[...order];
 const pos=order.indexOf(buyer),queue=[...order.slice(pos+1),...order.slice(0,pos)];
 game.phase='postPurchase';game.contract={type:'hokum',trump:game.upCard.suit,buyer,purchaseRound:1};
 game.postPurchase={buyer,stage,responses:{},queue,sunCallerSeat:null,ashkalCallerSeat:null,doubleCallerSeat:null};
 game.turn=queue[0]??buyer;return game;
}
test('أشكل متاح فقط للمقاعد المحددة حسب موقع المشتري',()=>{
 const order=[1,2,3,0],cases=[{buyerPos:1,eligible:[3,4]},{buyerPos:2,eligible:[3,4]},{buyerPos:3,eligible:[4]},{buyerPos:4,eligible:[3]}];
 for(const item of cases){const buyer=order[item.buyerPos-1],g=pendingPurchase(order,buyer);for(let pos=1;pos<=4;pos++){const seat=order[pos-1];g.turn=seat;const has=R.availablePostPurchaseOptions(g,seat).includes('ashkal');assert.equal(has,item.eligible.includes(pos),`buyer ${item.buyerPos}, responder ${pos}`);}}
});
test('الردود متتابعة ولا تظهر خيارات اللاعب التالي قبل دوره',()=>{
 const order=[1,2,3,0],g=pendingPurchase(order,1);
 assert.deepEqual(R.availablePostPurchaseOptions(g,2),['pass','sun']);
 R.respondPostPurchase(g,2,'pass');assert.equal(g.turn,3);
 assert.deepEqual(R.availablePostPurchaseOptions(g,3),['pass','sun','ashkal']);
 R.respondPostPurchase(g,3,'pass');assert.equal(g.turn,0);
 assert.deepEqual(R.availablePostPurchaseOptions(g,0),['pass','sun','ashkal']);
 R.respondPostPurchase(g,0,'pass');assert.equal(g.phase,'buyerConfirm');
 assert.deepEqual(R.availablePostPurchaseOptions(g,1),['confirmHukm','sun']);
});
test('أشكل ينقل ورقة الشراء للمقابل ويبقي المشتري صاحب أشكل',()=>{
 const order=[1,2,3,0],g=pendingPurchase(order,1);
 R.respondPostPurchase(g,2,'pass');R.respondPostPurchase(g,3,'pass');
 const receiver=2;
 R.respondPostPurchase(g,0,'ashkal');
 assert.equal(g.turn,3);assert.deepEqual(R.availablePostPurchaseOptions(g,3),['pass','sun']);
 for(const seat of [...g.postPurchase.queue])R.respondPostPurchase(g,seat,'pass');
 assert.equal(g.contract.type,'sun');assert.equal(g.contract.buyer,0);
 assert.equal(g.contract.cardReceiver,receiver);assert.ok(g.hands[receiver].some(card=>card.id===g.upCard.id));
 assert.equal(g.contract.purchaseType,'ashkal');
});
test('بعد أشكل، الصن ينتقل للذين قبل صاحبه ويثبت عند آخر من قال صن',()=>{
 const g=pendingPurchase([1,2,3,0],1);
 R.respondPostPurchase(g,2,'pass');R.respondPostPurchase(g,3,'pass');R.respondPostPurchase(g,0,'ashkal');
 R.respondPostPurchase(g,3,'sun');assert.equal(g.turn,2);
 R.respondPostPurchase(g,2,'pass');
 assert.equal(g.contract.type,'sun');assert.equal(g.contract.buyer,3);
});
test('الحكم الثاني ينتظر مرور الجميع ثم يطلب نوع الحكم ثم يتيح الدبل للفريق الآخر',()=>{
 const g=R.createGame();R.deal(g);g.phase='auction2';g.auctionOrder=[1,2,3,0];g.turn=1;
 R.chooseContract(g,1,'takeSecond');assert.equal(g.turn,2);
 R.respondPostPurchase(g,2,'pass');R.respondPostPurchase(g,3,'pass');R.respondPostPurchase(g,0,'pass');
 assert.equal(g.phase,'buyerSelectHukm');
 assert.deepEqual(R.availablePostPurchaseOptions(g,1),R.SUITS.filter(s=>s!==g.upCard.suit).concat('sun'));
 const suit=R.SUITS.find(s=>s!==g.upCard.suit);
 R.respondPostPurchase(g,1,suit);assert.equal(g.postPurchase.stage,'doubleResponse');
 const first=g.turn;assert.deepEqual(R.availablePostPurchaseOptions(g,first),['pass','double']);
 R.respondPostPurchase(g,first,'double');assert.equal(g.turn,1);
 R.respondPostPurchase(g,1,'pass');assert.equal(g.contract.level,2);assert.equal(g.contract.restricted,true);
 assert.ok(['playing','projectDeclaration'].includes(g.phase));
});
test('تصعيد الحكم: بس بعد ثري يثبت دبل، وبعد فور يثبت دبل ثري، وبعد بس المشتري يثبت دبل فور',()=>{
 const make=()=>{const g=R.createGame();R.deal(g);g.phase='auction2';g.auctionOrder=[1,2,3,0];g.turn=1;R.chooseContract(g,1,'takeSecond');for(const seat of [2,3,0])R.respondPostPurchase(g,seat,'pass');R.respondPostPurchase(g,1,R.SUITS.find(s=>s!==g.upCard.suit));return g;};
 let g=make();let caller=g.turn;R.respondPostPurchase(g,caller,'double');R.respondPostPurchase(g,1,'pass');assert.equal(g.contract.level,2);assert.equal(g.contract.restricted,true);
 g=make();caller=g.turn;R.respondPostPurchase(g,caller,'double');R.respondPostPurchase(g,1,'three');R.respondPostPurchase(g,caller,'pass');assert.equal(g.contract.level,3);assert.equal(g.contract.restricted,true);
 g=make();caller=g.turn;R.respondPostPurchase(g,caller,'double');R.respondPostPurchase(g,1,'three');R.respondPostPurchase(g,caller,'four');R.respondPostPurchase(g,1,'pass');assert.equal(g.contract.level,4);assert.equal(g.contract.restricted,true);
});
test('القهوة تنهي اللعب بعد احتساب الأبناط والمشاريع',()=>{
 const {scoreRound}=require('../game/scoring');
 const result=scoreRound({raw:[90,72],contract:{type:'hokum',buyer:0,buyerTeam:0,originalBuyerTeam:0,level:5},projectClaims:[],tricksWon:[4,4]});
 assert.equal(result.coffee,true);assert.deepEqual(result.awarded,[90,72]);assert.equal(result.roundWinner,0);
});
test('دبل الصن لا يظهر إلا للفريق الأقل من 100 عندما يكون المشتري أعلى من 100',()=>{
 const g=pendingPurchase([1,2,3,0],1,'sunChallenge');g.contract={type:'sun',buyer:1};g.postPurchase.sunCallerSeat=1;g.postPurchase.queue=[2,3,0];g.turn=2;g.scores=[90,120];
 assert.ok(R.availablePostPurchaseOptions(g,2).includes('double'));
 g.postPurchase.queue=[0];g.turn=0;
 assert.deepEqual(R.availablePostPurchaseOptions(g,0),['pass','takeBefore','double']);
 g.scores=[100,120];assert.ok(!R.availablePostPurchaseOptions(g,2).includes('double'));
 g.scores=[90,100];assert.ok(!R.availablePostPurchaseOptions(g,2).includes('double'));
});
test('انتهاء مهلة الرد يمرر اللاعبين المتبقين وينقل القرار للمشتري',()=>{
 const g=pendingPurchase([1,2,3,0],1);R.expirePostPurchase(g);
 assert.equal(g.phase,'buyerConfirm');assert.deepEqual(R.availablePostPurchaseOptions(g,1),['confirmHukm','sun']);
});

test('أشكل يحول العقد إلى صن حتى إذا مرّ اللاعبون قبله',()=>{
 const g=pendingPurchase([1,2,3,0],1);
 R.respondPostPurchase(g,2,'pass');R.respondPostPurchase(g,3,'pass');R.respondPostPurchase(g,0,'ashkal');
 for(const seat of [...g.postPurchase.queue])R.respondPostPurchase(g,seat,'pass');
 assert.equal(g.contract.type,'sun');assert.equal(g.contract.buyer,0);
 assert.equal(g.contract.cardReceiver,2);assert.ok(['projectDeclaration','playing'].includes(g.phase));
});
test('اختيار صن من المشتري بعد تأكيد الحكم يفتح سلسلة الصن ولا يعلق على تأكيد المشتري',()=>{
 const g=pendingPurchase([1,2,3,0],3);
 R.respondPostPurchase(g,0,'pass');R.respondPostPurchase(g,1,'pass');R.respondPostPurchase(g,2,'pass');
 R.respondPostPurchase(g,3,'sun');
 assert.equal(g.phase,'postPurchase');assert.equal(g.postPurchase.stage,'sunChallenge');
 assert.equal(g.turn,2);g.scores=[90,120];assert.deepEqual(R.availablePostPurchaseOptions(g,2),['pass','sun','double']);
});
test('تأكيد الحكم يفتح دبل للفريق المقابل قبل بدء اللعب',()=>{
 const g=pendingPurchase([1,2,3,0],1);
 R.respondPostPurchase(g,2,'pass');R.respondPostPurchase(g,3,'pass');R.respondPostPurchase(g,0,'pass');
 R.respondPostPurchase(g,1,'confirmHukm');
 assert.equal(g.phase,'postPurchase');assert.equal(g.postPurchase.stage,'doubleResponse');
 const opponent=g.turn;
 assert.notEqual(opponent%2,g.postPurchase.buyer%2);
 assert.deepEqual(R.availablePostPurchaseOptions(g,opponent),['pass','double']);
 R.respondPostPurchase(g,opponent,'double');assert.equal(g.contract.doubleCallerSeat,opponent);
});
