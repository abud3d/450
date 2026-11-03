const express=require('express');
const http=require('http');
const fs=require('fs');
const path=require('path');
const crypto=require('crypto');
const {Server}=require('socket.io');
const R=require('./game/rules');
const app=express(),server=http.createServer(app),io=new Server(server);
const PORT=process.env.PORT||3000,DATA=path.join(__dirname,'data','rooms.json');
app.use(express.static('public'));
app.get('/health',(_q,res)=>res.json({ok:true,service:'baloot-450',version:'0.2.0'}));
const rooms=new Map();
function persist(){try{fs.mkdirSync(path.dirname(DATA),{recursive:true});fs.writeFileSync(DATA,JSON.stringify([...rooms.values()]),{mode:0o600});}catch(e){console.error('persist failed',e.message);}}
try{if(fs.existsSync(DATA))for(const room of JSON.parse(fs.readFileSync(DATA,'utf8'))){room.players.forEach(p=>{if(p)p.socketId=null;});rooms.set(room.code,room);}}catch(e){console.error('restore failed',e.message);}
const code=()=>crypto.randomBytes(3).toString('hex').toUpperCase();
const cleanCard=c=>({id:c.id,suit:c.suit,rank:c.rank});
function snapshot(room,seat){
 const g=room.game;
 return {code:room.code,players:room.players.map((p,i)=>({seat:i,name:p?.name||'بانتظار لاعب',bot:!!p?.bot||!!p?.botControl,connected:!!p?.socketId})),
 phase:g?.phase||'lobby',turn:g?.turn??0,contract:g?.contract||null,upCard:g?.upCard||null,
 trick:(g?.trick||[]).map(p=>({seat:p.seat,card:cleanCard(p.card)})),scores:g?.scores||[0,0],
 lastRaw:g?.lastRaw||null,lastAwarded:g?.lastAwarded||null,dealScores:g?.dealScores||[],tricksWon:g?.tricksWon||[0,0],round:g?.round||0,
 hand:g?.hands?.[seat]?.map(cleanCard)||[],mySeat:seat,lastAction:room.lastAction||''};
}
function emit(room){room.players.forEach((p,seat)=>{if(p?.socketId)io.to(p.socketId).emit('room:update',snapshot(room,seat));});persist();}
function seatFor(room,socketId){return room.players.findIndex(p=>p?.socketId===socketId);}
function roomBySocket(socket){for(const room of rooms.values()){const seat=seatFor(room,socket.id);if(seat>=0)return {room,seat};}return null;}
function createRoom(name,socketId,guestId){let c;do{c=code();}while(rooms.has(c));const room={code:c,players:Array(4).fill(null),game:null,lastAction:'الغرفة جاهزة.'};room.players[0]={name,socketId,guestId,bot:false};rooms.set(c,room);return room;}
function botTurn(room){
 const g=room.game,seat=g.turn,p=room.players[seat];if(!p||(!p.bot&&!p.botControl))return;
 try{
  if(g.phase==='auction1'||g.phase==='auction2')R.chooseContract(g,seat,R.botBid(g,seat));
  else if(g.phase==='playing')R.playCard(g,seat,R.botPlay(g,seat));
  else return;
  room.lastAction='لعب '+(p.name||'البوت')+'.';emit(room);
  if(g.phase==='playing'||g.phase==='auction1'||g.phase==='auction2')setTimeout(()=>botTurn(room),450);
 }catch(e){room.lastAction='تعذر قرار البوت: '+e.message;emit(room);}
}
function startBots(room){setTimeout(()=>botTurn(room),300);}
io.on('connection',socket=>{
 socket.on('room:create',({name,guestId}={},ack=()=>{})=>{
  const room=createRoom(String(name||'ضيف').slice(0,24),socket.id,String(guestId||crypto.randomUUID()));
  socket.join(room.code);ack({ok:true,code:room.code,guestId:room.players[0].guestId});emit(room);
 });
 socket.on('room:join',({code:c,name,guestId}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase());if(!room)return ack({ok:false,error:'الغرفة غير موجودة'});
  const gid=String(guestId||crypto.randomUUID());
  let seat=room.players.findIndex(p=>p?.guestId===gid);
  if(seat>=0){room.players[seat].socketId=socket.id;room.players[seat].botControl=false;}
  else {seat=room.players.findIndex(p=>!p||p.bot);if(seat<0)return ack({ok:false,error:'الغرفة مكتملة'});room.players[seat]={name:String(name||'ضيف').slice(0,24),socketId:socket.id,guestId:gid,bot:false};}
  socket.join(room.code);ack({ok:true,code:room.code,seat,guestId:gid});emit(room);
 });
 socket.on('room:resume',({code:c,guestId}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase());if(!room)return ack({ok:false,error:'الغرفة غير موجودة'});
  const seat=room.players.findIndex(p=>p?.guestId===guestId);if(seat<0)return ack({ok:false,error:'تعذر استعادة المقعد'});
  room.players[seat].socketId=socket.id;room.players[seat].botControl=false;socket.join(room.code);ack({ok:true,seat});emit(room);
 });
 socket.on('room:start',({code:c}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase());if(!room||room.players[0]?.socketId!==socket.id)return ack({ok:false,error:'صاحب الغرفة فقط يبدأ اللعبة'});
  room.players=room.players.map(p=>p||{name:'بوت',bot:true,socketId:null,guestId:null});
  room.game=R.createGame(room.players.map(p=>p.name));room.game.players=room.players.map((p,i)=>({...p,seat:i}));R.deal(room.game);
  room.lastAction='وُزعت خمس أوراق لكل لاعب وبدأ الشراء.';ack({ok:true});emit(room);startBots(room);
 });
 socket.on('room:bid',({code:c,choice}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);if(!room||seat<0)return ack({ok:false,error:'المقعد غير معروف'});
  try{R.chooseContract(room.game,seat,choice);room.lastAction='تم تسجيل قرار الشراء.';ack({ok:true});emit(room);if(room.game.phase==='redeal'){R.nextRound(room.game);room.lastAction='مرّ الجميع؛ إعادة التوزيع.';emit(room);}startBots(room);}catch(e){ack({ok:false,error:e.message});}
 });
 socket.on('room:play',({code:c,cardId}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);if(!room||seat<0)return ack({ok:false,error:'المقعد غير معروف'});
  try{R.playCard(room.game,seat,cardId);room.lastAction='تم لعب الورقة.';ack({ok:true});emit(room);startBots(room);}catch(e){ack({ok:false,error:e.message});}
 });
 socket.on('room:next',({code:c}={},ack=()=>{})=>{
  const room=rooms.get(String(c||'').toUpperCase()),seat=room&&seatFor(room,socket.id);if(!room||seat!==0)return ack({ok:false,error:'صاحب الغرفة فقط يبدأ التوزيعة التالية'});
  try{R.nextRound(room.game);room.lastAction='بدأت توزيعة جديدة.';ack({ok:true});emit(room);startBots(room);}catch(e){ack({ok:false,error:e.message});}
 });
 socket.on('disconnect',()=>{
  for(const room of rooms.values()){const seat=seatFor(room,socket.id);if(seat<0)continue;
   const p=room.players[seat];p.socketId=null;room.lastAction='انقطع اتصال '+p.name+'؛ سيتولى البوت مقعده بعد مهلة قصيرة.';emit(room);
   setTimeout(()=>{if(!p.socketId){p.botControl=true;room.lastAction='البوت أكمل اللعب بدلاً من '+p.name+'.';emit(room);startBots(room);}},12000);
  }
 });
});
server.listen(PORT,()=>console.log('Baloot 400 listening on '+PORT));
