const express=require('express');
const http=require('http');
const fs=require('fs');
const path=require('path');
const crypto=require('crypto');
const {execFileSync}=require('child_process');
const {Server}=require('socket.io');
const R=require('./game/rules');
const {resolveProjects}=require('./game/projects');
const app=express(),server=http.createServer(app),io=new Server(server);
const PORT=process.env.PORT||3000,DATA=path.join(__dirname,'data','rooms.json');
let BUILD_COMMIT=process.env.GITHUB_SHA||process.env.COMMIT_SHA||'';
if(!BUILD_COMMIT){try{BUILD_COMMIT=execFileSync('git',['rev-parse','--short=7','HEAD'],{encoding:'utf8'}).trim();}catch{BUILD_COMMIT='local';}}
const BUILD_VERSION=process.env.BUILD_VERSION||'0.3.0-preview';
// Prefer the live server build identity over the static fallback file.
app.get('/build.json',(_q,res)=>res.set('Cache-Control','no-store').json({version:BUILD_VERSION,commit:BUILD_COMMIT}));
app.use(express.static('public',{etag:false,lastModified:false,setHeaders(res){res.setHeader('Cache-Control','no-store, no-cache, must-revalidate, proxy-revalidate');res.setHeader('Pragma','no-cache');res.setHeader('Expires','0');}}));

app.get('/health',(_q,res)=>res.set('Cache-Control','no-store').json({ok:true,service:'baloot-450',version:BUILD_VERSION,commit:BUILD_COMMIT}));
const rooms=new Map();
function persist(){try{fs.mkdirSync(path.dirname(DATA),{recursive:true});fs.writeFileSync(DATA,JSON.stringify([...rooms.values()]),{mode:0o600});}catch(e){console.error('persist failed',e.message);}}
try{if(fs.existsSync(DATA))for(const room of JSON.parse(fs.readFileSync(DATA,'utf8'))){room.ownerGuestId=room.ownerGuestId||room.players[0]?.guestId||null;room.players.forEach(p=>{if(p)p.socketId=null;});room.qaidWindow=null;room.qaidTimer=null;room.projectTimers={};rooms.set(room.code,room);}}catch(e){console.error('restore failed',e.message);}
const code=()=>crypto.randomBytes(3).toString('hex').toUpperCase();
const hasConnectedHuman=room=>room.players.some(p=>p&&!p.bot&&p.socketId);
const cleanCard=c=>({id:c.id,suit:c.suit,rank:c.rank});
const teamOfSeat=seat=>seat%2;
function snapshot(room,seat){
 const g=room.game;
 return {code:room.code,players:room.players.map((p,i)=>({seat:i,name:p?.name||'بانتظار لاعب',bot:!!p?.bot||!!p?.botControl,botControl:!!p?.botControl,botNumber:(p?.bot||p?.botControl)?(p.botNumber||i+10):null,connected:!!p?.socketId,empty:!p,isOwner:!!p&&p.guestId===room.ownerGuestId})),timeoutPrompt:room.timeoutSeat===seat,tricksPlayed:g?.tricks?.length||0,projectDisplayBySeat:g?.phase==='playing'&&g.tricks?.length===1&&g.projectClaims?(()=>{const resolved=resolveProjects(g.projectClaims,g.dealer,g.contract?.type||'sun');return g.projectClaims.map((claims,i)=>resolved.winnerTeam===i%2&&g.trick?.some(play=>play.seat===i)?claims.filter(p=>p.type!=='belot').map(p=>({...p,cards:p.cards||[]})):[]);})():[[],[],[],[]],
 phase:g?.phase||'lobby',turn:g?.turn??0,contract:g?.contract||null,upCard:g?.upCard||null,qaidEnabled:!!room.qaidEnabled,qaidHistory:(()=>{if(!room.qaidEnabled||!g||!['playing','roundEnd','gameEnd'].includes(g.phase)||g.qaidResult)return[];const groups=new Map();g.history.forEach((p,i)=>{if(!groups.has(p.trickIndex))groups.set(p.trickIndex,[]);groups.get(p.trickIndex).push({historyIndex:i,seat:p.seat,card:cleanCard(p.card),violation:!!p.violation,leadSuit:p.leadSuit||null,trickIndex:p.trickIndex});});return [...groups.entries()].filter(([,cards])=>cards.length>0).map(([trickIndex,cards])=>({trickIndex,cards}));})(),qaidReady:!!room.qaidEnabled&&!!g&&['playing','roundEnd','gameEnd'].includes(g.phase)&&!g.qaidResult,qaidDeadline:room.qaidWindow?.seat===seat?room.qaidWindow.expiresAt:null,qaidResult:g?.qaidResult||null,
 dealer:g?.dealer??0,buyer:g?.contract?.buyer??null,lastLandTeam:g?.lastLandTeam??null,lastLandPoints:g?.lastLandPoints??0,myProjects:(g?.tricks?.length===0?(g?.projectClaims?.[seat]||[]):[]),projectsBySeat:g?.tricks?.length===0?(g?.projectClaims||[[],[],[],[]]):[[],[],[],[]],handCounts:g?.hands?.map(h=>h.length)||[0,0,0,0],
 trick:(g?.trick||[]).map(p=>({seat:p.seat,card:cleanCard(p.card)})),roundResultReadyAt:room.roundResultReadyAt||0,resultVisible:!!g&&['roundEnd','gameEnd'].includes(g.phase)&&Date.now()>=(room.roundResultReadyAt||0),resultDismissed:room.roundDismissed||[false,false,false,false],qaidWindowActive:!!room.qaidWindow,tricksWonBySeat:(g?.tricks||[]).reduce((a,t)=>{a[t.winner]=(a[t.winner]||0)+1;return a;},[0,0,0,0]),lastTrick:(room.trickVisibleUntil>Date.now()?(g?.tricks?.at(-1)?.plays||[]).map(p=>({seat:p.seat,card:cleanCard(p.card)})):[]),trickClearing:room.trickVisibleUntil>Date.now(),
 runningRaw:(g?.tricks||[]).reduce((a,t)=>{a[t.team]+=t.points;return a;},[0,0]),currentTrickPoints:(g?.trick||[]).reduce((a,p)=>a+R.cardPoints(p.card,g.contract||{type:'sun'}),0),scores:g?.scores||[0,0],
 lastRaw:g?.lastRaw||null,lastAwarded:g?.lastAwarded||null,lastScoring:g?.lastScoring||null,dealScores:g?.dealScores||[],tricksWon:g?.tricksWon||[0,0],round:g?.round||0,
 hand:g?.hands?.[seat]?.map(cleanCard)||[],projectOptions:g?.projectOptions?.[seat]||[],projectDeclared:g?.projectDeclared||[false,false,false,false],doubleOptions:g?R.availableDoubleCalls(g,seat):[],postPurchaseOptions:g?R.availablePostPurchaseOptions(g,seat):[],postPurchaseBuyer:g?.postPurchase?.buyer??g?.contract?.buyer??null,postPurchaseTurn:g?.turn??null,mySeat:seat,lastAction:room.lastAction||''};
}
function emit(room){room.players.forEach((p,seat)=>{if(p?.socketId)io.to(p.socketId).emit('room:update',snapshot(room,seat));});persist();}
function seatFor(room,socketId){return room.players.findIndex(p=>p?.socketId===socketId);}
function roomBySocket(socket){for(const room of rooms.values()){const seat=seatFor(room,socket.id);if(seat>=0)return {room,seat};}return null;}
function createRoom(name,socketId,guestId,qaidEnabled=false){let c;do{c=code();}while(rooms.has(c));const room={code:c,players:Array(4).fill(null),game:null,ownerGuestId:guestId,qaidEnabled:!!qaidEnabled,projectTimers:{},lastAction:'الغرفة جاهزة.'};room.players[0]={name,socketId,guestId,bot:false};rooms.set(c,room);return room;}
function joinRoomSeat(room,name,socketId,guestId){
 let seat=room.players.findIndex(p=>p?.guestId===guestId);
 if(seat>=0){room.players[seat].socketId=socketId;room.players[seat].botControl=false;clearTurnTimeout(room);}
 else{seat=room.players.findIndex(p=>!p||p.bot);if(seat<0)return -1;room.players[seat]={name:String(name||'ضيف').slice(0,24),socketId,guestId,bot:false,botControl:false,botNumber:null};}
 return seat;
}
function findQuickRoom(requireQaid=false){
 const candidates=[...rooms.values()].filter(r=>!r.game&&r.players.some(p=>!p||p.bot)&&(!requireQaid||r.qaidEnabled));
 candidates.sort((a,b)=>b.players.filter(Boolean).length-a.players.filter(Boolean).length||Number(!!b.qaidEnabled)-Number(!!a.qaidEnabled));
 return candidates[0]||null;
}
function scheduleBot(room){
 if(room.qaidWindow||!hasConnectedHuman(room))return;
 if(room.botTimer)clearTimeout(room.botTimer);
 room.botTimer=setTimeout(()=>{room.botTimer=null;botTurn(room);},1050);
}
function clearTurnTimeout(room){if(room.turnTimer)clearTimeout(room.turnTimer);if(room.timeoutAutoTimer)clearTimeout(room.timeoutAutoTimer);room.turnTimer=null;room.timeoutAutoTimer=null;room.timeoutSeat=null;}
function clearPostPurchaseTimer(room){if(room.postPurchaseTimer)clearTimeout(room.postPurchaseTimer);room.postPurchaseTimer=null;}
function clearProjectTimer(room,seat){if(!room.projectTimers)room.projectTimers={};if(room.projectTimers[seat])clearTimeout(room.projectTimers[seat]);delete room.projectTimers[seat];}
function clearProjectTimers(room){if(!room.projectTimers)room.projectTimers={};Object.keys(room.projectTimers).forEach(seat=>clearProjectTimer(room,Number(seat)));}
function scheduleProjectTimeouts(room){
 const g=room.game;if(!g||g.phase!=='projectDeclaration')return;
 if(!room.projectTimers)room.projectTimers={};
 g.projectDeclared.forEach((declared,seat)=>{
  const player=room.players[seat],options=g.projectOptions?.[seat]||[];
  if(declared||!player||player.bot||player.botControl||!player.socketId||!options.length){clearProjectTimer(room,seat);return;}
  if(room.projectTimers[seat])return;
  room.projectTimers[seat]=setTimeout(()=>{
   delete room.projectTimers[seat];
   if(room.game!==g||g.phase!=='projectDeclaration'||g.projectDeclared[seat]||!room.players[seat]?.socketId)return;
   room.players[seat].botControl=true;room.lastAction='انتهت مهلة إعلان المشروع؛ الكمبيوتر يتولى المقعد ويختار المشروع المتاح.';
   emit(room);scheduleBot(room);
  },10000);
 });
}
function schedulePostPurchaseTimer(room){if(room.postPurchaseTimer)return;room.postPurchaseTimer=setTimeout(()=>{room.postPurchaseTimer=null;if(room.game?.phase!=='postPurchase')return;R.expirePostPurchase(room.game);room.lastAction='انتهت مهلة الرد؛ القرار الآن للمشتري.';emit(room);startBots(room);},10000);}
function scheduleTurnTimeout(room,seat){
 if(room.turnTimer)clearTimeout(room.turnTimer);
 room.timeoutSeat=null;
 room.turnTimer=setTimeout(()=>{room.turnTimer=null;const g=room.game,p=room.players[seat];if(!g||g.turn!==seat||!p||p.bot||p.botControl||!['auction1','auction2','playing'].includes(g.phase))return;room.timeoutSeat=seat;room.lastAction='تأخر اللاعب؛ سيتولى البوت اللعب تلقائياً خلال ثانيتين.';emit(room);room.timeoutAutoTimer=setTimeout(()=>{room.timeoutAutoTimer=null;if(room.timeoutSeat!==seat||room.game?.turn!==seat||room.players[seat]?.socketId==null)return;room.timeoutSeat=null;room.players[seat].botControl=true;room.lastAction='انتهت مهلة اللاعب؛ البوت يكمل اللعب.';emit(room);startBots(room);},2000);},10000);
}

function botTurn(room){
 const g=room.game;if(!g||room.qaidWindow||!hasConnectedHuman(room))return;
 try{
  if(g.phase==='postPurchase'){
   const seat=g.turn,p=room.players[seat];if(!p||(!p.bot&&!p.botControl))return;
   const options=R.availablePostPurchaseOptions(g,seat);
   const choice=options.includes('pass')?'pass':options[0];
   if(!choice)return;
   R.respondPostPurchase(g,seat,choice);room.lastAction='سجل البوت رده: '+choice;emit(room);startBots(room);return;
  }
  if(g.phase==='buyerConfirm'){
   const seat=g.postPurchase?.buyer,p=room.players[seat];if(!p||(!p.bot&&!p.botControl))return;
   R.respondPostPurchase(g,seat,'confirmHukm');room.lastAction='أكد البوت الحكم.';emit(room);startBots(room);return;
  }
  if(g.phase==='buyerSelectHukm'){
   const seat=g.postPurchase?.buyer,p=room.players[seat];if(!p||(!p.bot&&!p.botControl))return;
   const choice=R.SUITS.find(s=>s!==g.upCard.suit)||'sun';
   R.respondPostPurchase(g,seat,choice);room.lastAction='اختار البوت نوع الحكم الثاني.';emit(room);startBots(room);return;
  }
  if(g.phase==='projectDeclaration'){
   const seat=room.players.findIndex((p,i)=>p&&(p.bot||p.botControl)&&!g.projectDeclared[i]);
   if(seat<0)return;
   R.declareProject(g,seat,R.botProject(g,seat));
   room.lastAction='أعلن البوت مشروعه.';emit(room);startBots(room);return;
  }
  const seat=g.turn,p=room.players[seat];if(!p||(!p.bot&&!p.botControl))return;
  if(g.phase==='auction1'||g.phase==='auction2')R.chooseContract(g,seat,R.botBid(g,seat));
  else if(g.phase==='playing'){const previousTricks=g.tricks.length;R.playCard(g,seat,R.botPlay(g,seat));if(g.tricks.length>previousTricks)showCompletedTrick(room,g.phase==='roundEnd'||g.phase==='gameEnd'?6000:2200);}
  else return;
  room.lastAction=g.phase==='roundEnd'?(g.lastScoring.buyerSucceeded?'المشتري ناجح':'دبل ناجح'):'لعب '+(p.name||'البوت')+'.';if(['roundEnd','gameEnd'].includes(g.phase))scheduleRoundResult(room);emit(room);
  if(g.phase==='redeal'){R.nextRound(g);room.lastAction='مرّ الجميع؛ أعيد التوزيع تلقائياً.';emit(room);scheduleBot(room);}
  else if(g.phase==='roundEnd'||g.phase==='gameEnd')return;
  else startBots(room);
 }catch(e){room.lastAction='تعذر قرار البوت: '+e.message;emit(room);}
}
function scheduleNextDeal(room,immediate=false){if(room.nextDealTimer)clearTimeout(room.nextDealTimer);if(!hasConnectedHuman(room)){room.nextDealTimer=null;return;}const advance=()=>{room.nextDealTimer=null;if(room.game?.phase!=='roundEnd'||room.qaidWindow||!hasConnectedHuman(room))return;try{R.nextRound(room.game);room.roundResultReadyAt=0;room.roundDismissed=[false,false,false,false];room.lastAction='انتهت مهلة النشرة وبدأت التوزيعة التالية تلقائياً.';emit(room);startBots(room);}catch(e){room.lastAction='تعذر بدء الجولة التالية: '+e.message;emit(room);}};if(immediate){advance();return;}room.nextDealTimer=setTimeout(advance,10000);}
function scheduleRoundResult(room){if(room.roundResultTimer)clearTimeout(room.roundResultTimer);room.roundResultReadyAt=Date.now()+6000;room.roundDismissed=[false,false,false,false];room.trickVisibleUntil=room.roundResultReadyAt;room.roundResultTimer=setTimeout(()=>{room.roundResultTimer=null;if(!['roundEnd','gameEnd'].includes(room.game?.phase))return;room.lastAction='ظهرت نشرة النتيجة؛ تبدأ التوزيعة التالية تلقائياً بعد 10 ثوانٍ.';emit(room);if(room.game.phase==='roundEnd')scheduleNextDeal(room);},6000);}
function showCompletedTrick(room,duration){const until=Date.now()+duration;room.trickVisibleUntil=until;if(duration<6000)setTimeout(()=>{if(room.trickVisibleUntil===until&&Date.now()>=until){room.trickVisibleUntil=0;emit(room);}},duration+80);}
function scheduleAutoLastCard(room){if(room.lastCardTimer||room.game?.phase!=='playing'||room.qaidWindow)return;const g=room.game,seat=g.turn;if(g.hands?.[seat]?.length!==1)return;room.lastCardTimer=setTimeout(()=>{room.lastCardTimer=null;if(room.game!==g||g.phase!=='playing'||room.qaidWindow||g.turn!==seat||g.hands?.[seat]?.length!==1)return;try{const previousTricks=g.tricks.length;R.playCard(g,seat,g.hands[seat][0].id);if(g.tricks.length>previousTricks)showCompletedTrick(room,g.phase==='roundEnd'||g.phase==='gameEnd'?6000:2200);room.lastAction='لُعبت الورقة الأخيرة تلقائياً.';if(['roundEnd','gameEnd'].includes(g.phase))scheduleRoundResult(room);emit(room);if(g.phase==='playing')startBots(room);}catch(e){room.lastAction='تعذر لعب الورقة الأخيرة تلقائياً: '+e.message;emit(room);}},750);}
function allResultDismissed(room){return room.players.every((p,seat)=>!p?.socketId||p.bot||p.botControl||room.roundDismissed?.[seat]);}
function startBots(room){
 const g=room.game;if(!g||room.qaidWindow||!hasConnectedHuman(room))return;
 if(g.phase==='playing'&&g.hands?.[g.turn]?.length===1){scheduleAutoLastCard(room);return;}
 if(g.phase==='projectDeclaration'){
  let passed=false;
  for(let seat=0;seat<4;seat++)if(!g.projectDeclared[seat]&&!(g.projectOptions?.[seat]||[]).length){R.declareProject(g,seat,'pass');passed=true;}
  if(passed){room.lastAction='تم تجاوز إعلان المقاعد التي لا تملك مشروعاً.';emit(room);}
  if(g.phase!=='projectDeclaration'){clearProjectTimers(room);return startBots(room);}
  scheduleProjectTimeouts(room);
  const botSeat=room.players.findIndex((p,i)=>p&&(p.bot||p.botControl)&&!g.projectDeclared[i]);
  if(botSeat>=0)scheduleBot(room);
  return;
 }
 clearProjectTimers(room);
 if(g.phase==='postPurchase')schedulePostPurchaseTimer(room);else clearPostPurchaseTimer(room);
 if(['postPurchase','buyerConfirm','buyerSelectHukm'].includes(g.phase)){const seat=(g.phase==='buyerConfirm'||g.phase==='buyerSelectHukm')?g.postPurchase?.buyer:g.turn;const p=room.players[seat];if(p?.bot||p?.botControl)scheduleBot(room);return;}if(['auction1','auction2','playing'].includes(g.phase)){const p=room.players[g.turn];if(p?.bot||p?.botControl){clearTurnTimeout(room);scheduleBot(room);}else if(p?.socketId){scheduleTurnTimeout(room,g.turn);}return;}scheduleBot(room);}
io.on('connection',socket=>{
 socket.on('room:quick-join',({name,guestId,qaidEnabled}={},ack=()=>{})=>{
  const gid=String(guestId||crypto.randomUUID()),playerName=String(name||'ضيف').slice(0,24),enabled=!!qaidEnabled;
  let room=findQuickRoom(enabled),seat,created=false;
  if(room){seat=joinRoomSeat(room,playerName,socket.id,gid);if(seat<0)room=null;}
  if(!room){room=createRoom(playerName,socket.id,gid,enabled);seat=0;created=true;}
  socket.join(room.code);room.lastAction=room.players.filter(Boolean).length===4?'اكتمل عدد اللاعبين؛ الغرفة جاهزة.':'انضم لاعب إلى الغرفة.';
  ack({ok:true,code:room.code,seat,guestId:gid,created,qaidEnabled:!!room.qaidEnabled});emit(room);startBots(room);
 });
 socket.on('room:create',({name,guestId,qaidEnabled}={},ack=()=>{})=>{
  const room=createRoom(String(name||'ضيف').slice(0,24),socket.id,String(guestId||crypto.randomUUID()),qaidEnabled);
  socket.join(room.code);ack({ok:true,code:room.code,guestId:room.players[0].guestId});emit(room);
 });
 socket.on('room:join',({code:c,name,guestId}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase());if(!room)return ack({ok:false,error:'الغرفة غير موجودة'});
  const gid=String(guestId||crypto.randomUUID());
  const seat=joinRoomSeat(room,String(name||'ضيف').slice(0,24),socket.id,gid);
  if(seat<0)return ack({ok:false,error:'الغرفة مكتملة'});
  socket.join(room.code);ack({ok:true,code:room.code,seat,guestId:gid});emit(room);startBots(room);
 });
 socket.on('room:resume',({code:c,guestId}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase());if(!room)return ack({ok:false,error:'الغرفة غير موجودة'});
  const seat=room.players.findIndex(p=>p?.guestId===guestId);if(seat<0)return ack({ok:false,error:'تعذر استعادة المقعد'});
  room.players[seat].socketId=socket.id;room.players[seat].botControl=false;clearTurnTimeout(room);socket.join(room.code);ack({ok:true,seat});emit(room);startBots(room);
 });
 socket.on('room:move-seat',({code:c,targetSeat}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);
  if(!room||seat<0)return ack({ok:false,error:'المقعد غير معروف'});
  if(room.game)return ack({ok:false,error:'تغيير المقاعد متاح قبل بدء اللعبة فقط'});
  if(!Number.isInteger(targetSeat)||targetSeat<0||targetSeat>3||targetSeat===seat)return ack({ok:false,error:'المقعد المطلوب غير صحيح'});
  const target=room.players[targetSeat];if(target&&!target.bot)return ack({ok:false,error:'لا يمكن نقل لاعب آخر من مقعده'});
  const current=room.players[seat];room.players[seat]=target?.bot?target:null;room.players[targetSeat]=current;
  room.lastAction='غيّر اللاعب مقعده قبل بدء اللعبة.';ack({ok:true,seat:targetSeat});emit(room);
 });
 socket.on('room:start',({code:c}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase());if(!room||room.ownerGuestId!==room.players.find(p=>p?.socketId===socket.id)?.guestId)return ack({ok:false,error:'صاحب الغرفة فقط يبدأ اللعبة'});
  room.players=room.players.map((p,i)=>p||{name:'كمبيوتر '+(i+10),bot:true,botNumber:i+10,socketId:null,guestId:null});
  room.game=R.createGame(room.players.map(p=>p.name));room.game.qaidEnabled=!!room.qaidEnabled;room.game.players=room.players.map((p,i)=>({...p,seat:i}));R.deal(room.game);
  room.lastAction='وُزعت خمس أوراق لكل لاعب وبدأ الشراء.';ack({ok:true});emit(room);startBots(room);
 });
 socket.on('room:timeout-return',({code:c}={},ack=()=>{})=>{const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);if(!room||seat<0)return ack({ok:false,error:'المقعد غير معروف'});if(room.timeoutSeat!==seat&&!room.players[seat]?.botControl)return ack({ok:false,error:'لا يوجد طلب عودة لهذا المقعد'});room.players[seat].botControl=false;clearTurnTimeout(room);ack({ok:true});room.lastAction='عاد اللاعب لمقعده.';emit(room);startBots(room);});
 socket.on('room:timeout-bot',({code:c}={},ack=()=>{})=>{const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);if(!room||seat<0||room.timeoutSeat!==seat)return ack({ok:false,error:'انتهت مهلة الاختيار أو لم يحن وقتها'});clearTurnTimeout(room);room.players[seat].botControl=true;ack({ok:true});room.lastAction='البوت يكمل اللعب بدلاً من اللاعب رقم '+(seat+1)+'.';emit(room);startBots(room);});
 socket.on('room:bid',({code:c,choice}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);if(!room||seat<0)return ack({ok:false,error:'المقعد غير معروف'});if(room.players[seat]?.botControl)return ack({ok:false,error:'البوت يدير هذا المقعد حالياً'});
  try{clearTurnTimeout(room);R.chooseContract(room.game,seat,choice);room.lastAction='تم تسجيل قرار الشراء.';ack({ok:true});emit(room);if(room.game.phase==='redeal'){R.nextRound(room.game);room.lastAction='مرّ الجميع؛ إعادة التوزيع.';emit(room);}startBots(room);}catch(e){ack({ok:false,error:e.message});}
 });
 socket.on('room:post-purchase',({code:c,choice}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);if(!room||seat<0)return ack({ok:false,error:'المقعد غير معروف'});if(room.players[seat]?.botControl)return ack({ok:false,error:'البوت يدير هذا المقعد حالياً'});
  try{const oldPhase=room.game.phase;R.respondPostPurchase(room.game,seat,choice);if(room.game.phase!=='postPurchase')clearPostPurchaseTimer(room);room.lastAction='تم تسجيل رد اللاعب.';ack({ok:true});emit(room);startBots(room);}catch(e){ack({ok:false,error:e.message});}
 });
 socket.on('room:double',({code:c,call}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);if(!room||seat<0)return ack({ok:false,error:'المقعد غير معروف'});if(room.players[seat]?.botControl)return ack({ok:false,error:'البوت يدير هذا المقعد حالياً'});
  try{if(room.qaidWindow)return ack({ok:false,error:'اللعب متوقف مؤقتاً أثناء كشف أوراق القيد'});R.callDouble(room.game,seat,call);room.lastAction='تم التصعيد إلى '+({double:'دبل',three:'ثري',four:'فور',coffee:'قهوة'}[call]||call)+'.';ack({ok:true});emit(room);}catch(e){ack({ok:false,error:e.message});}
 });
 socket.on('room:project',({code:c,projectType}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);if(!room||seat<0)return ack({ok:false,error:'المقعد غير معروف'});if(room.players[seat]?.botControl)return ack({ok:false,error:'البوت يدير هذا المقعد حالياً'});
  try{clearProjectTimer(room,seat);R.declareProject(room.game,seat,projectType);room.lastAction=projectType==='pass'?'مررت إعلان المشاريع.':'تم إعلان مشروعك.';ack({ok:true});emit(room);startBots(room);}catch(e){ack({ok:false,error:e.message});}
 });
 socket.on('room:play',({code:c,cardId}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);if(!room||seat<0)return ack({ok:false,error:'المقعد غير معروف'});if(room.players[seat]?.botControl)return ack({ok:false,error:'البوت يدير هذا المقعد حالياً'});
  try{if(room.qaidWindow)return ack({ok:false,error:'اللعب متوقف مؤقتاً أثناء كشف أوراق القيد'});clearTurnTimeout(room);const previousTricks=room.game.tricks.length;R.playCard(room.game,seat,cardId);if(room.game.tricks.length>previousTricks)showCompletedTrick(room,room.game.phase==='roundEnd'||room.game.phase==='gameEnd'?6000:2200);room.lastAction=room.game.phase==='roundEnd'?(room.game.lastScoring.buyerSucceeded?'المشتري ناجح':'دبل ناجح'):'تم لعب الورقة.';if(['roundEnd','gameEnd'].includes(room.game.phase))scheduleRoundResult(room);ack({ok:true});emit(room);if(room.game.phase==='roundEnd'||room.game.phase==='gameEnd')return;else startBots(room);}catch(e){ack({ok:false,error:e.message});}
 });
 socket.on('room:qaid-open',({code:c}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);if(!room||seat<0)return ack({ok:false,error:'المقعد غير معروف'});
  if(!room.qaidEnabled||!room.game||!['playing','roundEnd','gameEnd'].includes(room.game.phase)||(room.game.phase==='roundEnd'&&Date.now()<(room.roundResultReadyAt||0)))return ack({ok:false,error:'طلب القيد غير متاح الآن'});
  if(room.qaidWindow)return ack({ok:false,error:'هناك طلب قيد جارٍ بالفعل'});
  clearTurnTimeout(room);if(room.botTimer)clearTimeout(room.botTimer);room.botTimer=null;
  const pausedNextDeal=!!room.nextDealTimer;if(room.nextDealTimer)clearTimeout(room.nextDealTimer);room.nextDealTimer=null;
  const expiresAt=Date.now()+60000;room.roundDismissed=[false,false,false,false];room.qaidWindow={seat,expiresAt,pausedNextDeal};
  room.qaidTimer=setTimeout(()=>{room.qaidTimer=null;if(!room.qaidWindow||room.qaidWindow.expiresAt!==expiresAt)return;room.qaidWindow=null;room.lastAction='انتهت مهلة كشف أوراق القيد.';room.roundDismissed=[false,false,false,false];emit(room);if(room.game?.phase==='playing')startBots(room);else if(room.game?.phase==='roundEnd')scheduleNextDeal(room);},60000);
  ack({ok:true,expiresAt});emit(room);
 });
 socket.on('room:qaid-cancel',({code:c}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);if(room&&room.qaidWindow?.seat===seat){if(room.qaidTimer)clearTimeout(room.qaidTimer);room.qaidTimer=null;room.qaidWindow=null;room.lastAction='أُغلق كشف القيد.';room.roundDismissed=[false,false,false,false];emit(room);if(room.game?.phase==='playing')startBots(room);else if(room.game?.phase==='roundEnd')scheduleNextDeal(room);}ack({ok:true});
 });
 socket.on('room:claim',({code:c,violationIndex,proofIndex}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);if(!room||seat<0)return ack({ok:false,error:'المقعد غير معروف'});
  try{if(room.qaidWindow?.seat!==seat||Date.now()>room.qaidWindow.expiresAt)throw Error('انتهت مهلة كشف أوراق القيد؛ افتح الطلب من جديد');const result=R.claimQaid(room.game,seat,violationIndex,proofIndex);if(room.qaidTimer)clearTimeout(room.qaidTimer);room.qaidTimer=null;room.qaidWindow=null;room.lastAction=result.success?'نجح طلب القيد؛ احتسبت النتيجة للفريق المطالب.':'فشل طلب القيد؛ احتسبت النتيجة للفريق المتهم.';room.roundDismissed=[false,false,false,false];room.roundResultReadyAt=Date.now();ack({ok:true,result});emit(room);if(room.game.phase==='roundEnd')scheduleNextDeal(room);}catch(e){ack({ok:false,error:e.message});}
 });
 socket.on('room:result-dismiss',({code:c}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);
  if(!room||seat<0||room.game?.phase!=='roundEnd'||Date.now()<(room.roundResultReadyAt||0)||room.qaidWindow)return ack({ok:false,error:'النشرة غير جاهزة للإغلاق'});
  room.roundDismissed=room.roundDismissed||[false,false,false,false];room.roundDismissed[seat]=true;ack({ok:true});emit(room);
  if(allResultDismissed(room))scheduleNextDeal(room,true);
 });
 socket.on('room:result-continue',({code:c}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);
  if(!room||seat<0||room.game?.phase!=='roundEnd'||Date.now()<(room.roundResultReadyAt||0)||room.qaidWindow)return ack({ok:false,error:'النشرة غير جاهزة للإغلاق'});
  room.roundDismissed=room.roundDismissed||[false,false,false,false];room.roundDismissed[seat]=true;ack({ok:true});emit(room);if(allResultDismissed(room))scheduleNextDeal(room,true);
 });
 socket.on('room:replay',({code:c}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);
  if(!room||seat<0||room.game?.phase!=='gameEnd')return ack({ok:false,error:'إعادة اللعب متاحة بعد انتهاء الصكة فقط'});
  try{
   room.players=room.players.map((p,i)=>({...p,bot:!!p.bot||!p.socketId,botControl:false,botNumber:p.botNumber||i+10}));
   room.game=R.createGame(room.players.map(p=>p.name));room.game.qaidEnabled=!!room.qaidEnabled;room.game.players=room.players.map((p,i)=>({...p,seat:i}));R.deal(room.game);
   room.lastAction='بدأت صكة جديدة بنفس المقاعد.';ack({ok:true});emit(room);startBots(room);
  }catch(e){ack({ok:false,error:e.message});}
 });
 socket.on('room:next',({code:c}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);if(!room||seat!==0)return ack({ok:false,error:'صاحب الغرفة فقط يبدأ التوزيعة التالية'});
  try{if(room.nextDealTimer)clearTimeout(room.nextDealTimer);R.nextRound(room.game);room.lastAction='بدأت توزيعة جديدة.';ack({ok:true});emit(room);startBots(room);}catch(e){ack({ok:false,error:e.message});}
 });
 socket.on('disconnect',()=>{
  for(const room of rooms.values()){const seat=seatFor(room,socket.id);if(seat<0)continue;
   const p=room.players[seat];p.socketId=null;
   if(!hasConnectedHuman(room)){
    clearTurnTimeout(room);if(room.botTimer)clearTimeout(room.botTimer);clearPostPurchaseTimer(room);if(room.nextDealTimer)clearTimeout(room.nextDealTimer);room.botTimer=null;room.nextDealTimer=null;room.lastAction='الغرفة محفوظة بانتظار عودة أحد اللاعبين.';emit(room);continue;
   }
   room.lastAction='انقطع اتصال '+p.name+'؛ سيتولى البوت مقعده بعد مهلة قصيرة.';emit(room);
   setTimeout(()=>{if(!p.socketId&&hasConnectedHuman(room)){p.botControl=true;room.lastAction='البوت أكمل اللعب بدلاً من '+p.name+'.';emit(room);startBots(room);}},12000);
  }
 });
});
server.listen(PORT,'0.0.0.0',()=>console.log('Baloot 400 listening on '+PORT));
