const socket=io(),$=id=>document.getElementById(id);
let roomCode=null,mySeat=null,latest=null;
let guestId=localStorage.getItem('baloot450Guest')||crypto.randomUUID();localStorage.setItem('baloot450Guest',guestId);
const name=()=> $('playerName').value.trim()||'ضيف';
const status=m=>$('status').textContent=m;
const emit=(event,data)=>socket.emit(event,data,r=>{if(!r?.ok)status(r?.error||'تعذر تنفيذ الطلب');});
$('createRoom').onclick=()=>socket.emit('room:create',{name:name(),guestId},r=>{if(!r.ok)return status(r.error);roomCode=r.code;localStorage.setItem('baloot450Room',roomCode);$('table').classList.remove('hidden');status('أنشأت الغرفة. أرسل الرمز لأصدقائك.');});
$('joinRoom').onclick=()=>socket.emit('room:join',{code:$('roomCode').value,name:name(),guestId},r=>{if(!r.ok)return status(r.error);roomCode=r.code;mySeat=r.seat;localStorage.setItem('baloot450Room',roomCode);status('انضممت إلى الغرفة.');});
$('startGame').onclick=()=>emit('room:start',{code:roomCode});
$('nextDeal').onclick=()=>emit('room:next',{code:roomCode});
socket.on('connect',()=>{status('متصل بالخادم.');const saved=localStorage.getItem('baloot450Room');if(saved){roomCode=saved;socket.emit('room:resume',{code:saved,guestId},r=>{if(r.ok){mySeat=r.seat;status('تم استعادة مقعدك.');}});}});
socket.on('disconnect',()=>status('انقطع الاتصال؛ نحاول إعادة الاتصال.'));
socket.on('room:update',s=>{latest=s;roomCode=s.code;mySeat=s.mySeat;$('table').classList.remove('hidden');$('code').textContent=s.code;
 $('seats').innerHTML=s.players.map(p=>'<div class="seat"><b>المقعد '+(p.seat+1)+' · '+escapeHtml(p.name)+'</b><small>'+(p.bot?'بوت':p.connected?'متصل':'منقطع / بانتظار العودة')+'</small></div>').join('');
 $('phase').textContent=phaseName(s.phase,s.contract);$('score').textContent='فريقك '+(s.mySeat%2===0?s.scores[0]:s.scores[1])+' — الخصم '+(s.mySeat%2===0?s.scores[1]:s.scores[0]);
 $('upcard').innerHTML=s.upCard&&['auction1','auction2'].includes(s.phase)?'<span>الورقة المكشوفة: </span><span class=\"card\">'+s.upCard.rank+symbol(s.upCard.suit)+'</span>':'';
 $('roundResult').textContent=s.lastAwarded?'نقاط التوزيعة: '+s.lastAwarded.join(' — ')+' | مجموع الصكة: '+s.scores.join(' — '):'';
 $('trick').innerHTML=s.trick.length?s.trick.map(x=>'<span class="card">'+x.card.rank+symbol(x.card.suit)+'<small>مقعد '+(x.seat+1)+'</small></span>').join(''):'تبدأ اللفة عند اختيار الورقة';
 $('hand').innerHTML=(s.hand||[]).map(c=>'<button class="card '+('HD'.includes(c.suit)?'red':'')+'" data-card="'+c.id+'">'+c.rank+symbol(c.suit)+'</button>').join('');
 document.querySelectorAll('[data-card]').forEach(b=>b.onclick=()=>emit('room:play',{code:roomCode,cardId:b.dataset.card}));
 renderControls(s);status(s.lastAction||'دور اللاعب '+(s.turn+1));
});
function renderControls(s){const el=$('controls');el.innerHTML='';
 if(s.phase==='lobby')return;
 if(['auction1','auction2'].includes(s.phase)&&s.turn===s.mySeat){
  const choices=s.phase==='auction1'?[['take','أشتري حكم'],['pass','بس']]: [['sun','صن'],['S','حكم ♠'],['H','حكم ♥'],['D','حكم ♦'],['C','حكم ♣'],['pass','بس']];
  choices.forEach(([v,t])=>{const b=document.createElement('button');b.textContent=t;b.className=v==='pass'?'secondary':'';b.onclick=()=>emit('room:bid',{code:roomCode,choice:v});el.appendChild(b);});
 }
 if(['roundEnd','redeal'].includes(s.phase)){const b=document.createElement('button');b.textContent='التوزيعة التالية';b.onclick=()=>emit('room:next',{code:roomCode});el.appendChild(b);}
}
function phaseName(p,c){if(p==='auction1')return 'الشراء الأول — الورقة المكشوفة';if(p==='auction2')return 'الشراء الثاني — صن أو حكم';if(p==='playing')return 'اللعب · '+(c?.type==='sun'?'صن':'حكم '+symbol(c?.trump));if(p==='roundEnd')return 'انتهت التوزيعة · نقاط الجولة: '+(latest.lastRaw||[]).join(' — ');if(p==='gameEnd')return 'انتهت الصكة';if(p==='redeal')return 'إعادة التوزيع';return p;}
function symbol(s){return ({S:'♠',H:'♥',D:'♦',C:'♣'})[s]||s}
function escapeHtml(v){return String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
