const transport=new BalootHttpClient(),$=id=>document.getElementById(id);
let roomCode=null,mySeat=null,latest=null,account=null,selectedCardId=null,qaidState=null,qaidTimer=null,lastSeenPlayCount=null,dismissedResultRound=null;
let lastRenderedHandIds=[],dealAnimationToken=0;
let guestId=localStorage.getItem('baloot400Guest')||crypto.randomUUID();localStorage.setItem('baloot400Guest',guestId);
const ROOM_TTL=60*60*1000;
function saveRoomCode(code){localStorage.setItem('baloot400Room',code);localStorage.setItem('baloot400RoomAt',String(Date.now()));}
function getRecentRoom(){const code=localStorage.getItem('baloot400Room'),at=Number(localStorage.getItem('baloot400RoomAt')||0);if(!code||!at||Date.now()-at>ROOM_TTL){localStorage.removeItem('baloot400Room');localStorage.removeItem('baloot400RoomAt');return '';}return code;}
const savedName=localStorage.getItem('baloot400PlayerName');if(savedName)$('playerName').value=savedName;
const name=()=> $('playerName').value.trim()||'ضيف';
$('playerName').addEventListener('input',()=>localStorage.setItem('baloot400PlayerName',$('playerName').value));
const status=m=>{$('status').textContent=m;};
const emit=(event,data)=>transport.emit(event,data,r=>{if(!r?.ok)status(r?.error||'تعذر الطلب');else status('تم');});
async function authRequest(url,body={}){
 const r=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
 const data=await r.json();if(!r.ok||!data.ok)throw new Error(data.message||data.error||'تعذر تنفيذ الطلب');return data;
}
function showAccount(user){
 account=user||null;
 if(account?.guestId){guestId=account.guestId;localStorage.setItem('baloot400Guest',guestId);}
 if(account?.displayName){$('playerName').value=account.displayName;localStorage.setItem('baloot400PlayerName',account.displayName);}
 $('accountStatus').textContent=account?'مرحباً '+account.displayName+' · '+account.email:'تلعب كضيف. أنشئ حساباً لحفظ هويتك ونقاطك ونتائجك.';
 $('logoutAccount').classList.toggle('hidden',!account);$('registerAccount').classList.toggle('hidden',!!account);$('loginAccount').classList.toggle('hidden',!!account);$('forgotAccount').classList.toggle('hidden',!!account);
 $('profilePanel').classList.toggle('hidden',!account);
 if(!account)return;
 $('profileDisplayName').value=account.displayName||'';$('profileAvatar').value=account.avatar||'🃏';$('profileBio').value=account.bio||'';
 const stats=account.stats||{};$('profilePoints').textContent=Number(stats.points||0).toLocaleString('ar-SA');$('profileMatches').textContent=Number(stats.matches||0).toLocaleString('ar-SA');$('profileWins').textContent=Number(stats.wins||0).toLocaleString('ar-SA');$('profileLosses').textContent=Number(stats.losses||0).toLocaleString('ar-SA');
}
async function applyAuth(data){
 showAccount(data.user);
 if(roomCode)transport.emit('room:resume',{code:roomCode,guestId},r=>{if(r.ok){mySeat=r.seat;status('تم استعادة مقعدك من حسابك.');}});
}
$('registerAccount').onclick=async()=>{try{const d=await authRequest('/api/auth.php?action=register',{email:$('accountEmail').value,password:$('accountPassword').value,displayName:$('accountName').value||name()});$('accountStatus').textContent=d.message;}catch(e){$('accountStatus').textContent=e.message;}};
$('loginAccount').onclick=async()=>{try{const d=await authRequest('/api/auth.php?action=login',{email:$('accountEmail').value,password:$('accountPassword').value,remember:false});await applyAuth(d);}catch(e){$('accountStatus').textContent=e.message;}};
$('forgotAccount').onclick=async()=>{try{const email=$('accountEmail').value.trim();if(!email)throw new Error('أدخل بريدك الإلكتروني أولاً.');const d=await authRequest('/api/auth.php?action=forgot',{email});$('accountStatus').textContent=d.message;}catch(e){$('accountStatus').textContent=e.message;}};
$('resetPasswordButton').onclick=async()=>{try{const token=new URLSearchParams(location.search).get('reset')||'';const d=await authRequest('/api/auth.php?action=reset',{token,password:$('resetPassword').value});history.replaceState({},'',location.pathname);$('resetPanel').classList.add('hidden');$('accountStatus').textContent=d.message+' يمكنك الآن تسجيل الدخول.';}catch(e){$('accountStatus').textContent=e.message;}};
$('saveProfile').onclick=async()=>{try{const d=await authRequest('/api/auth.php?action=profile',{displayName:$('profileDisplayName').value,avatar:$('profileAvatar').value,bio:$('profileBio').value});showAccount(d.user);$('profileMessage').textContent='تم حفظ الملف الشخصي.';}catch(e){$('profileMessage').textContent=e.message;}};
$('logoutAccount').onclick=async()=>{try{await authRequest('/api/auth.php?action=logout',{});}catch(e){}showAccount(null);};
async function restoreAccount(){
 if(new URLSearchParams(location.search).has('reset')){$('resetPanel')?.classList.remove('hidden');$('account')?.scrollIntoView({behavior:'smooth',block:'center'});}
 try{const d=await authRequest('/api/auth.php?action=me',{});showAccount(d.user);if(roomCode)transport.emit('room:resume',{code:roomCode,guestId},r=>{if(r.ok){mySeat=r.seat;status('تم استعادة مقعدك من حسابك.');}});}
 catch{showAccount(null);}
}
$('createRoom').onclick=()=>transport.emit('room:create',{name:name(),guestId,qaidEnabled:!!$('qaidEnabled')?.checked},r=>{if(!r.ok)return status(r.error);roomCode=r.code;saveRoomCode(roomCode);location.href='/table.html?room='+encodeURIComponent(roomCode);});
$('quickJoin')?.addEventListener('click',()=>{const button=$('quickJoin');button.disabled=true;button.textContent='جارٍ البحث عن غرفة...';transport.emit('room:quick-join',{name:name(),guestId,qaidEnabled:!!$('qaidEnabled')?.checked},r=>{button.disabled=false;button.textContent='دخول غرفة / العب الآن';if(!r?.ok)return status(r?.error||'تعذر العثور على غرفة');roomCode=r.code;mySeat=r.seat;saveRoomCode(roomCode);status(r.created?'لم توجد غرفة مناسبة؛ أنشأنا لك غرفة جديدة.':r.qaidEnabled?'تم إدخالك إلى غرفة مفعّل فيها القيد.':'دخلت غرفة انتظار، لكن قاعدة القيد غير مفعّلة فيها.');location.href='/table.html?room='+encodeURIComponent(roomCode);});});
$('joinRoom').onclick=()=>transport.emit('room:join',{code:$('roomCode').value,name:name(),guestId},r=>{if(!r.ok)return status(r.error);roomCode=r.code;mySeat=r.seat;saveRoomCode(roomCode);location.href='/table.html?room='+encodeURIComponent(roomCode);});
$('startGame')?.addEventListener('click',()=>emit('room:start',{code:roomCode}));
function registerGameApp(){if('serviceWorker'in navigator)window.addEventListener('load',()=>navigator.serviceWorker.register('/sw.js').catch(()=>{}));}
registerGameApp();
$('shareRoom')?.addEventListener('click',async()=>{if(!roomCode)return;const url=location.origin+'/?room='+encodeURIComponent(roomCode);try{if(navigator.share)await navigator.share({title:'بلوت 400',text:'انضم إلى غرفة البلوت',url});else if(navigator.clipboard?.writeText){await navigator.clipboard.writeText(url);status('تم نسخ رابط الغرفة.');}else{const field=document.createElement('textarea');field.value=url;document.body.appendChild(field);field.select();document.execCommand('copy');field.remove();status('تم نسخ رابط الغرفة.');}}catch(e){if(e.name!=='AbortError')status('تعذرت مشاركة الرابط.');}});
const roomFromUrl=new URLSearchParams(location.search).get('room');if(roomFromUrl&&$('roomCode'))$('roomCode').value=roomFromUrl.toUpperCase();
transport.on('connect',()=>{if($('status'))status('متصل بالخادم.');const target=roomFromUrl?.toUpperCase()||getRecentRoom();if(target){roomCode=target;if($('roomCode'))$('roomCode').value=target;transport.emit('room:resume',{code:target,guestId},r=>{if(r.ok){mySeat=r.seat;if($('status'))status('تم استعادة مقعدك.');}else if($('status'))status(r.error||'تعذر فتح الغرفة.');});}});
transport.on('disconnect',()=>status('انقطع الاتصال؛ نحاول إعادة الاتصال.'));
transport.on('room:update',s=>{if(s.phase!=='roundEnd')dismissedResultRound=null;if(s.qaidWindowActive&&!latest?.qaidWindowActive)dismissedResultRound=null;if(s.qaidResult&&!latest?.qaidResult)dismissedResultRound=null;latest=s;roomCode=s.code;mySeat=s.mySeat;$('table').classList.remove('hidden');$('code').textContent=s.code;$('shareRoom')?.setAttribute('data-room',s.code);
 s.players.forEach((p)=>{const rel=(p.seat-s.mySeat+4)%4,el=$('seat'+rel);if(!el)return;el.className='seat seat-'+['bottom','left','top','right'][rel]+(p.seat===s.turn?' active-turn':'');const backs=rel===0?'':Array.from({length:Math.min(8,s.handCounts?.[p.seat]??0)},(_,i)=>'<i class="back-card" style="transform:rotate('+((i-3.5)*5)+'deg) translateY('+ (Math.abs(i-3.5)*2) +'px)"></i>').join('');const isAuction=['auction1','auction2','projectDeclaration'].includes(s.phase);const dealerTag=p.seat===s.dealer&&isAuction?'<div class="seat-role">موزع</div>':'';const contractTag=(p.seat===s.buyer&&s.contract&&['projectDeclaration','playing','postPurchase','buyerConfirm','buyerSelectHukm'].includes(s.phase)?'<span class="seat-contract">'+(s.contract.type==='sun'?'صن':'حكم <b class="'+(s.contract.trump==='D'?'contract-diamond':'')+'">'+symbol(s.contract.trump)+'</b>')+'</span>':'')+(s.contract?.purchaseType==='ashkal'&&s.contract?.ashkalCallerSeat===p.seat?'<span class="seat-contract">أشكل</span>':'')+((s.contract?.doubleCallerSeat===p.seat||s.contract?.lastCallerSeat===p.seat)?'<span class="seat-contract">دبل</span>':'');const seatProjects=(s.projectsBySeat?.[p.seat]||[]).map(x=>x.label||({'20':'سرا','50':'خمسين','100-run':'مائة متسلسلة','100-set':'مائة ورق','400':'أربعمائة'}[x.type]||x.type)).join(' · ');const projectTag=seatProjects&&s.phase==='playing'&&!s.tricksPlayed?'<span class="seat-project">'+escapeHtml(seatProjects)+'</span>':'';const turnTag='';const displayCards=(s.projectDisplayBySeat?.[p.seat]||[]).flatMap(x=>x.cards||[]);const projectCards=displayCards.length?'<div class="project-cards" aria-label="أوراق المشروع">'+displayCards.map(c=>'<span class="project-card '+('HD'.includes(c.suit)?'red':'')+'">'+cardFace(c)+'</span>').join('')+'</div>':'';el.innerHTML='<div class="opponent-hand">'+backs+'</div><div class="seat-identity"><div class="seat-name" title="'+escapeHtml(p.bot?'كمبيوتر '+(p.botNumber||p.seat+10):p.name)+'">'+escapeHtml(p.bot?'كمبيوتر '+(p.botNumber||p.seat+10):p.name)+'</div><div class="seat-badges">'+turnTag+dealerTag+contractTag+projectTag+'</div></div>'+projectCards;
 if(s.phase==='lobby'&&p.seat!==s.mySeat&&(p.empty||p.bot)){el.classList.add('seat-selectable');el.setAttribute('role','button');el.setAttribute('tabindex','0');el.setAttribute('aria-label','الانتقال إلى مقعد '+(p.bot?'الكمبيوتر':'بانتظار لاعب'));el.onclick=()=>{const targetLabel=p.bot?'مكان الكمبيوتر':'مكان بانتظار لاعب';if(confirm('الانتقال إلى '+targetLabel+'؟'))transport.emit('room:move-seat',{code:roomCode,targetSeat:p.seat},r=>{if(!r?.ok)status(r?.error||'تعذر تغيير المقعد');else status('تم تغيير مقعدك.');});};}else{el.classList.remove('seat-selectable');el.onclick=null;el.removeAttribute('role');el.removeAttribute('tabindex');}
 });
 $('startGame').hidden=!(s.phase==='lobby'&&s.players[s.mySeat]?.isOwner);
 
 const playCount=(s.tricksPlayed||0)*4+(s.trick?.length||0);
 const newPlay=lastSeenPlayCount!==null&&playCount>lastSeenPlayCount;
 lastSeenPlayCount=playCount;
 $('phase').innerHTML=phaseDisplay(s.phase,s.contract);$('score').innerHTML='<div class="score-panel"><div class="score-points"><small>الأبناط</small><b>'+(s.mySeat%2===0?(s.runningRaw?.[0]||0):(s.runningRaw?.[1]||0))+' - '+(s.mySeat%2===0?(s.runningRaw?.[1]||0):(s.runningRaw?.[0]||0))+'</b></div><div class="score-teams"><span class="score-team">لنا <b>'+(s.mySeat%2===0?s.scores[0]:s.scores[1])+'</b></span><span class="score-team">لهم <b>'+(s.mySeat%2===0?s.scores[1]:s.scores[0])+'</b></span></div></div><div id="qaidTopControl"></div>';
 $('upcard').innerHTML=['postPurchase','buyerConfirm','buyerSelectHukm','projectDeclaration'].includes(s.phase)&&s.upCard?'<span class="upcard-visible '+('HD'.includes(s.upCard.suit)?'red':'')+'">'+cardFace(s.upCard)+'</span>':'';
 const sc=s.lastScoring;
 const visibleTrick=s.trick.length?s.trick:(s.lastTrick||[]);const announcement=['roundEnd','gameEnd'].includes(s.phase)&&!s.trickClearing?(s.lastScoring?.buyerSucceeded?'مشتري ناجح':(s.contract?.level>=2?'دبل ناجح':'المشتري خسر')):'';const totalWon=(s.tricksWonBySeat||[]).reduce((sum,n)=>sum+(n||0),0);const pileCount=Math.min(52,totalWon*4);const wonBackdrop=pileCount?'<div class="won-backdrop" aria-hidden="true">'+Array.from({length:pileCount},(_,i)=>'<i class="back-card" style="transform:translate('+((i-(pileCount-1)/2)*1.1)+'px,'+((i%5)*.45)+'px) rotate('+((i-(pileCount-1)/2)*.28)+'deg)"></i>').join('')+'</div>':'';$('trick').innerHTML=wonBackdrop+(announcement&&s.resultVisible?'<div class="center-announcement">'+announcement+'</div>':visibleTrick.length?visibleTrick.map((x,i)=>'<span class="played-card '+(s.trickClearing?'clearing ':'')+(newPlay&&i===visibleTrick.length-1?'card-arriving ':'')+'at-'+((x.seat-s.mySeat+4)%4)+' '+('HD'.includes(x.card.suit)?'red':'')+'">'+cardFace(x.card)+'</span>').join(''):(['auction1','auction2'].includes(s.phase)&&s.upCard?'<span class="played-card '+('HD'.includes(s.upCard.suit)?'red':'')+'">'+cardFace(s.upCard)+'</span>':''));
 const resultPage=$('resultPage'),qaidPage=$('qaidPage');const showResult=['roundEnd','gameEnd'].includes(s.phase)&&s.resultVisible;resultPage.innerHTML=showResult?roundSummary(s):'';resultPage.classList.toggle('hidden',!showResult);$('board').classList.toggle('hidden',showResult||!!qaidState);$('controls').classList.toggle('hidden',showResult||!!qaidState);$('qaidHeaderButton').hidden=!s.qaidEnabled||!['playing','roundEnd','gameEnd'].includes(s.phase);$('qaidHeaderButton').disabled=!!s.qaidWindowActive;$('qaidHeaderButton').title='طلب القيد متاح';

 const openQaid=()=>{if(!s.qaidEnabled||!['playing','roundEnd','gameEnd'].includes(s.phase)||(s.phase==='roundEnd'&&!s.resultVisible)||s.qaidWindowActive)return status('طلب القيد غير متاح الآن.');transport.emit('room:qaid-open',{code:roomCode},r=>{if(!r?.ok)return status(r?.error||'تعذر بدء مهلة القيد');qaidState={step:'firstTrick',firstTrick:null,violationIndex:null,secondTrick:null,proofIndex:null,deadline:r.expiresAt};if(qaidTimer)clearInterval(qaidTimer);qaidTimer=setInterval(()=>{if(!qaidState)return;const left=Math.max(0,Math.ceil((qaidState.deadline-Date.now())/1000));const clock=document.querySelector('#qaidPage .qaid-countdown');if(clock)clock.textContent='المتبقي: '+left+' ثانية';if(left<=0){clearInterval(qaidTimer);qaidTimer=null;qaidState=null;$('qaidPage').classList.add('hidden');$('resultPage').classList.toggle('hidden',['roundEnd','gameEnd'].includes(latest?.phase)&&!!latest?.resultVisible);$('board').classList.toggle('hidden',['roundEnd','gameEnd'].includes(latest?.phase)&&!!latest?.resultVisible);$('controls').classList.toggle('hidden',['roundEnd','gameEnd'].includes(latest?.phase)&&!!latest?.resultVisible);status('انتهت مهلة كشف أوراق القيد.');if(latest)renderControls(latest);}},250);if(latest)renderControls(latest);});};const qaidSummaryButton=$('resultPage').querySelector('[data-qaid-open]');if(qaidSummaryButton)qaidSummaryButton.onclick=openQaid;$('qaidHeaderButton').onclick=openQaid;
 const replayButton=$('resultPage').querySelector('[data-replay]');if(replayButton)replayButton.onclick=e=>{e.stopPropagation();replayButton.disabled=true;transport.emit('room:replay',{code:roomCode},r=>{if(!r?.ok){replayButton.disabled=false;status(r?.error||'تعذرت إعادة اللعب');}else status('بدأت صكة جديدة.');});};
 const continueButton=$('resultPage').querySelector('[data-continue]');if(continueButton)continueButton.onclick=()=>{continueButton.disabled=true;transport.emit('room:result-continue',{code:roomCode},r=>{if(!r?.ok){continueButton.disabled=false;status(r?.error||'تعذر إكمال التوزيعة');}else status('تم تسجيل طلبك؛ ستبدأ التوزيعة التالية عند اكتمال تأكيد اللاعبين أو انتهاء المهلة.');});};
 $('pointsLive').textContent='';
 const handCards=s.hand||[];const previousHandIds=new Set(lastRenderedHandIds);const handChanged=handCards.length!==lastRenderedHandIds.length||handCards.some(c=>!previousHandIds.has(c.id));const newHandIds=new Set(handChanged?handCards.filter(c=>!previousHandIds.has(c.id)).map(c=>c.id):[]);if(handChanged){dealAnimationToken++;lastRenderedHandIds=handCards.map(c=>c.id);}const dealToken=dealAnimationToken;$('hand').innerHTML=handCards.map((c,i,arr)=>'<button class="playing-card '+('HD'.includes(c.suit)?'red':'')+(selectedCardId===c.id?' selected':'')+(newHandIds.has(c.id)?' card-dealing':'')+'" style="--fan-y:'+Math.round(Math.abs(i-(arr.length-1)/2)*3.5)+'px;--fan-r:'+((i-(arr.length-1)/2)*5)+'deg;--deal-delay:'+(newHandIds.has(c.id)?[...newHandIds].indexOf(c.id)*150:0)+'ms" data-card="'+c.id+'">'+cardFace(c)+'</button>').join('');
 document.querySelectorAll('.full-card-art').forEach(img=>{img.onerror=()=>{img.hidden=true;const fallback=img.nextElementSibling;if(fallback)fallback.hidden=false;};});
 $('myProjects').innerHTML='';
 document.querySelectorAll('[data-card]').forEach(b=>{b.disabled=!!s.players[s.mySeat]?.botControl;b.onclick=()=>{if(s.phase!=='playing'||s.turn!==s.mySeat||s.players[s.mySeat]?.botControl)return;const id=b.dataset.card;const mobile=window.matchMedia('(max-width: 560px)').matches;if(mobile&&selectedCardId!==id){selectedCardId=id;document.querySelectorAll('[data-card]').forEach(x=>x.classList.toggle('selected',x.dataset.card===id));return;}selectedCardId=null;b.disabled=true;b.classList.add('is-playing');setTimeout(()=>emit('room:play',{code:roomCode,cardId:id}),135);};});
 const timeout=$('turnTimeout');if(timeout)timeout.classList.toggle('hidden',!s.timeoutPrompt);renderControls(s);$('gameNotice').textContent='';
});
function renderControls(s){const el=$('controls');el.innerHTML='';const top=$('qaidTopControl');if(top)top.innerHTML='';if(qaidState&&s.qaidEnabled&&['playing','roundEnd','gameEnd'].includes(s.phase)&&!s.qaidResult){renderQaidChooser(s,$('qaidPage'));$('board').classList.add('hidden');$('controls').classList.add('hidden');return;}else if(s.qaidResult&&qaidState){qaidState=null;if(qaidTimer)clearInterval(qaidTimer);qaidTimer=null;$('qaidPage').classList.add('hidden');$('resultPage').classList.remove('hidden');}
 if(s.players[s.mySeat]?.botControl){const back=document.createElement('button');back.textContent='العودة للعب';back.className='secondary bot-return';back.onclick=()=>{back.disabled=true;emit('room:timeout-return',{code:roomCode});};el.appendChild(back);return;}
 if(s.phase==='lobby')return;
 if(s.phase==='projectDeclaration'&&!s.projectDeclared?.[s.mySeat]){
  const labels={'20':'سرا','50':'خمسين','100-run':'مائة متسلسلة','100-set':'مائة ورق','400':'أربعمائة'};
  (s.projectOptions||[]).forEach(p=>{const b=document.createElement('button');b.textContent='أعلن '+(labels[p.type]||p.label);b.onclick=()=>emit('room:project',{code:roomCode,projectType:p.type});el.appendChild(b);});
  const pass=document.createElement('button');pass.textContent='بدون مشروع';pass.className='secondary';pass.onclick=()=>emit('room:project',{code:roomCode,projectType:'pass'});el.appendChild(pass);
  return;
 }
 if(s.phase==='projectDeclaration'){return;}
 if(s.phase==='postPurchase'||s.phase==='buyerConfirm'||s.phase==='buyerSelectHukm'){
  const labels={pass:'بس',sun:'صن',double:'دبل',ashkal:'أشكل',confirmHukm:'تأكيد الحكم',takeBefore:'قبلك',S:'حكم ♠',H:'حكم ♥',D:'حكم ♦',C:'حكم ♣'};
  const options=s.postPurchaseOptions||[];
  if(options.length){options.forEach(choice=>{const b=document.createElement('button');b.textContent=labels[choice]||choice;b.className=['pass','confirmHukm'].includes(choice)?'secondary':'';b.onclick=()=>{b.disabled=true;emit('room:post-purchase',{code:roomCode,choice});};el.appendChild(b);});}
  
  return;
 }
 if(['auction1','auction2'].includes(s.phase)&&s.turn===s.mySeat){
  const add=(v,t,secondary=false)=>{const b=document.createElement('button');b.textContent=t;b.className=secondary?'secondary':'';b.onclick=()=>{b.disabled=true;emit('room:bid',{code:roomCode,choice:v});};el.appendChild(b);};
  add('pass',s.phase==='auction2'?'ولا':'بس',true);
  if(s.phase==='auction2'){
   add('takeSecond','حكم ثاني');
   add('sun','صن');
   return;
  }
  const hukm=document.createElement('button');hukm.textContent='حكم';hukm.onclick=()=>{
   hukm.disabled=true;emit('room:bid',{code:roomCode,choice:'take'});
  };el.appendChild(hukm);add('sun','صن');
 }
 if(s.phase==='playing'&&s.doubleOptions?.length){
  s.doubleOptions.forEach(call=>{const labels={double:'دبل',three:'ثري',four:'فور',coffee:'قهوة'};const b=document.createElement('button');b.textContent=labels[call]||call;b.className='secondary';b.onclick=()=>{b.disabled=true;emit('room:double',{code:roomCode,call});};el.appendChild(b);});
 }
 if(s.phase==='roundEnd')return;
 if(s.phase==='gameEnd')return;
}
function renderQaidChooser(s,container){
 const history=s.qaidHistory||[],state=qaidState;if(!state)return;
 const left=Math.max(0,Math.ceil((state.deadline-Date.now())/1000));
 if(left<=0){qaidState=null;if(qaidTimer)clearInterval(qaidTimer);qaidTimer=null;container.innerHTML='';$('qaidPage').classList.add('hidden');$('resultPage').classList.toggle('hidden',['roundEnd','gameEnd'].includes(s.phase)&&!!s.resultVisible);$('board').classList.toggle('hidden',['roundEnd','gameEnd'].includes(s.phase)&&!!s.resultVisible);$('controls').classList.toggle('hidden',['roundEnd','gameEnd'].includes(s.phase)&&!!s.resultVisible);return;}
 container.innerHTML='';
 const panel=document.createElement('div');panel.className='qaid-panel qaid-visual-panel';
 const head=document.createElement('div');head.className='qaid-board-head';
 const title=document.createElement('strong');title.textContent='كشف أوراق القيد';head.appendChild(title);
 const headActions=document.createElement('div');headActions.className='qaid-head-actions';
 const clock=document.createElement('span');clock.className='qaid-countdown';clock.textContent='المتبقي: '+left+' ثانية';headActions.appendChild(clock);
 const headClose=document.createElement('button');headClose.type='button';headClose.className='secondary qaid-head-close';headClose.textContent='إغلاق';headClose.onclick=()=>{qaidState=null;if(qaidTimer)clearInterval(qaidTimer);qaidTimer=null;container.innerHTML='';$('qaidPage').classList.add('hidden');const current=latest||s,showResult=['roundEnd','gameEnd'].includes(current.phase)&&!!current.resultVisible;$('resultPage').classList.toggle('hidden',!showResult);$('board').classList.toggle('hidden',showResult);$('controls').classList.toggle('hidden',showResult);transport.emit('room:qaid-cancel',{code:roomCode},()=>{if(latest)renderControls(latest);});if(!showResult)renderControls(current);};headActions.appendChild(headClose);
 head.appendChild(headActions);panel.appendChild(head);
 const instruction=document.createElement('p');instruction.className='qaid-instruction';
 instruction.textContent='اختر الورقة المخالفة باللون الأحمر ثم أكد طلب القيد. لا يلزم اختيار ورقة إثبات ثانية.';panel.appendChild(instruction);
 const legend=document.createElement('div');legend.className='qaid-legend';
 legend.innerHTML='<span class="qaid-legend-cheat">الورقة المخالفة</span>';panel.appendChild(legend);
 const board=document.createElement('div');board.className='qaid-history-board';
 const findCard=index=>{for(const trick of history){const found=trick.cards.find(x=>x.historyIndex===index);if(found)return found;}return null;};
 history.forEach((trick,rowIndex)=>{
  const row=document.createElement('section');row.className='qaid-trick-row';
  const label=document.createElement('div');label.className='qaid-trick-label';label.textContent='اللفة '+(trick.trickIndex+1);row.appendChild(label);
  const cards=document.createElement('div');cards.className='qaid-card-grid';
  trick.cards.forEach(item=>{
   const isCheat=state.violationIndex===item.historyIndex,isProof=state.proofIndex===item.historyIndex;
   const ownTeam=item.seat%2===s.mySeat%2;
   const b=document.createElement('button');b.type='button';
   b.className='qaid-card-choice'+(isCheat?' qaid-cheat-selected':'')+(isProof?' qaid-proof-selected':'');
   b.disabled=ownTeam;
   b.title=ownTeam?'أوراق فريقك لا يمكن اتهامها':item.violation?'ورقة مخالفة مسجلة ويمكن اختيارها':'لا توجد مخالفة مسجلة على هذه الورقة';
   b.innerHTML='<span class="qaid-card-name">'+cardLabel(item.card)+'</span><span class="qaid-card-art">'+cardFace(item.card)+'</span><small>اللاعب '+(item.seat+1)+'</small>'+(isCheat?'<b class="qaid-card-tag">ورقة الغش</b>':isProof?'<b class="qaid-card-tag">ورقة التأكيد</b>':item.violation?'<b class="qaid-card-tag qaid-card-tag-available">مخالفة</b>':'');
   b.onclick=()=>{
    const index=item.historyIndex;
    if(ownTeam)return;
    if(!item.violation){status('هذه الورقة ليست مخالفة مسجلة؛ اختر الورقة المعلّمة كمخالفة.');return;}
    if(state.violationIndex===index){state.violationIndex=null;}
    else{state.violationIndex=index;}
    renderQaidChooser(s,container);
   };
   cards.appendChild(b);
  });
  row.appendChild(cards);board.appendChild(row);
 });
 if(!history.length){const empty=document.createElement('p');empty.textContent='لم تُلعب أوراق بعد؛ ستظهر هنا الورقة المخالفة بمجرد لعبها.';board.appendChild(empty);}
 panel.appendChild(board);
 const actions=document.createElement('div');actions.className='qaid-actions';
 if(state.violationIndex!==null){
  const first=findCard(state.violationIndex);
  const summary=document.createElement('p');summary.className='qaid-selection-summary';
  summary.textContent='الورقة المخالفة: '+(first?cardLabel(first.card):'—');actions.appendChild(summary);
  const confirm=document.createElement('button');confirm.type='button';confirm.className='qaid-confirm qaid-head-confirm';confirm.textContent='تأكيد طلب القيد';
  confirm.onclick=()=>{confirm.disabled=true;transport.emit('room:claim',{code:roomCode,violationIndex:state.violationIndex},r=>{if(!r?.ok){status(r?.error||'تعذر طلب القيد');confirm.disabled=false;}else{qaidState=null;if(qaidTimer)clearInterval(qaidTimer);qaidTimer=null;container.innerHTML='';$('qaidPage').classList.add('hidden');const current=latest||s;const showResult=['roundEnd','gameEnd'].includes(current.phase);$('resultPage').classList.toggle('hidden',!showResult);$('board').classList.toggle('hidden',showResult);$('controls').classList.toggle('hidden',showResult);status('تم حسم طلب القيد.');if(latest)renderControls(latest);}});};
  headActions.insertBefore(confirm,clock);
 }else{
  const missing=document.createElement('p');missing.className='qaid-selection-summary';missing.textContent='اختر الورقة المخالفة فقط.';actions.appendChild(missing);
 }
 panel.appendChild(actions);container.appendChild(panel);$('qaidPage').classList.remove('hidden');$('resultPage').classList.add('hidden');
 panel.querySelectorAll('.full-card-art').forEach(img=>{img.onerror=()=>{img.hidden=true;const fallback=img.nextElementSibling;if(fallback)fallback.hidden=false;};if(img.complete&&!img.naturalWidth)img.onerror();});
}
function cardLabel(c){const ranks={7:'7',8:'8',9:'9',10:'10',J:'ولد',Q:'بنت',K:'شايب',A:'آس'},suits={S:'♠',H:'♥',D:'♦',C:'♣'};return (ranks[c.rank]||c.rank)+' '+(suits[c.suit]||c.suit);}
function roundSummary(s){
 const awarded=s.lastAwarded||s.lastScoring?.awarded||[0,0],raw=s.lastScoring?.raw||s.lastRaw||[0,0],projects=s.lastScoring?.scoredProjectPoints||[0,0],scores=s.scores||[0,0],mine=s.mySeat%2===0?0:1,theirs=1-mine,landTeam=s.lastLandTeam,land=s.lastLandPoints||0;
 const result=s.qaidResult?(s.qaidResult.success?(s.qaidResult.winnerTeam===mine?'القيد صحيح — النتيجة لنا':'القيد صحيح — النتيجة لهم'):(s.qaidResult.winnerTeam===mine?'القيد غير صحيح — النتيجة لنا':'القيد غير صحيح — النتيجة لهم')):s.phase==='gameEnd'?'انتهت الصكة':s.lastScoring?.buyerSucceeded?'المشتري ناجح':'المشتري خسران';
 const projectList=team=>{const all=[...(s.lastScoring?.projects?.counted||[]),...(s.lastScoring?.projects?.belots||[])].filter(p=>p.team===team);const counts={};all.forEach(p=>{const label=p.label||p.type;counts[label]=(counts[label]||0)+1;});return Object.entries(counts).map(([label,count])=>label+(count>1?' ×'+count:'')).join('، ')||'—';};
 const title=s.phase==='gameEnd'?'انتهت الصكة':'نشرة التوزيعة';
 const team=(label,t)=>{const cardPoints=raw[t]-(landTeam===t?land:0);return '<section class="result-team"><strong>'+label+'</strong><div class="result-cell"><small>أبناط الورق</small><b>'+cardPoints+'</b></div><div class="result-cell"><small>الأرض</small><b>'+(landTeam===t?'+'+land:'—')+'</b></div><div class="result-cell"><small>المشاريع</small><b>'+projects[t]+'</b><em>'+escapeHtml(projectList(t))+'</em></div><div class="result-cell"><small>نقاط التوزيعة</small><b>'+awarded[t]+'</b></div><div class="result-cell result-total"><small>إجمالي الصكة</small><b>'+scores[t]+'</b></div></section>';};
 const actions='<div class="round-summary-actions">'+(s.phase==='roundEnd'?'<button type="button" class="round-summary-continue" data-continue>أكمل</button>':'<button type="button" class="round-summary-replay" data-replay>إعادة اللعب</button>')+(s.qaidEnabled&&!s.qaidResult?'<button type="button" class="round-summary-qaid" data-qaid-open>قيد</button>':'')+'</div>';
 return '<div class="round-summary" role="dialog" aria-modal="true" aria-label="'+title+'"><header><strong class="round-summary-title">'+title+'</strong><small>'+(s.phase==='roundEnd'?'تبدأ التوزيعة التالية تلقائياً بعد 10 ثوانٍ':'النتيجة النهائية للصكة')+'</small></header><div class="round-summary-result">'+result+'</div><div class="round-summary-grid">'+team('لنا',mine)+team('لهم',theirs)+'</div>'+actions+'</div>';
}
function phaseDisplay(p,c){if(p==='playing'){const sun=c?.type==='sun';const mark=sun?'':' <span class="contract-icon '+(c?.trump==='D'?'diamond ':'')+('HD'.includes(c?.trump)?'red':'')+'">'+symbol(c?.trump)+'</span>';const escalation=c?.level>1?' · '+({2:'دبل',3:'ثري',4:'فور',5:'قهوة'}[c.level]||''):'';return (sun?'صن':'حكم')+mark+escalation;}return escapeHtml(phaseName(p,c));}
function phaseName(p,c){const escalation=c?.level>1?' · '+({2:'دبل',3:'ثري',4:'فور',5:'قهوة'}[c.level]||''):'';if(p==='lobby'||p==='dealing')return 'انتظار...';if(p==='projectDeclaration')return 'مشاريع';if(p==='postPurchase')return 'الرد على الحكم';if(p==='buyerConfirm')return 'تأكيد الحكم';if(p==='buyerSelectHukm')return 'اختيار نوع الحكم الثاني';if(p==='auction1'||p==='auction2')return 'شراء';if(p==='playing')return (c?.type==='sun'?'صن':'حكم '+symbol(c?.trump))+escalation;if(p==='roundEnd')return 'نهاية التوزيعة'+escalation;if(p==='gameEnd')return 'انتهت الصكة'+escalation;if(p==='redeal')return 'إعادة';return p;}
function cardFace(c){
 const rank=String(c.rank),suits={S:'spades',H:'hearts',D:'diamonds',C:'clubs'};
 const label=({7:'سبعة',8:'ثمانية',9:'تسعة',10:'عشرة',J:'ولد',Q:'بنت',K:'شايب',A:'آس'})[rank]||rank;
 const ranks={7:'7',8:'8',9:'9',10:'10',J:'jack',Q:'queen',K:'king',A:'ace'};
 const file=encodeURIComponent(ranks[rank]+' of '+suits[c.suit]+'.svg');
 const src='https://cdn.jsdelivr.net/gh/AustinGabriel/Public-Domain-and-CC0-Playing-Cards@main/svg%20cards/card%20fronts/'+suits[c.suit]+'/'+file;
 return '<img class="full-card-art" alt="'+label+' '+symbol(c.suit)+'" draggable="false" src="'+src+'"><span class="card-fallback" hidden><b>'+rank+'</b><i>'+symbol(c.suit)+'</i></span>';
}
function symbol(s){return ({S:'♠',H:'♥',D:'♦',C:'♣'})[s]||s}
function escapeHtml(v){return String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
async function loadBuildInfo(){
 try{const r=await fetch('/build.json',{cache:'no-store'});if(!r.ok)throw new Error('build info unavailable');const b=await r.json();$('buildVersion').textContent='نسخة '+b.version+' · Build '+b.commit;}
 catch{$('buildVersion').textContent='نسخة 0.3.0-preview · Build preview-2026-11-02.2 (واجهة)';}
}
$('timeoutReturn')?.addEventListener('click',()=>{ $('turnTimeout').classList.add('hidden');emit('room:timeout-return',{code:roomCode});});
$('timeoutBot')?.addEventListener('click',()=>{ $('turnTimeout').classList.add('hidden');emit('room:timeout-bot',{code:roomCode});});

// Keep modal layers outside the scrolling table so touch/click targets are never clipped by table ancestors.
const resultLayer=$('resultPage'),qaidLayer=$('qaidPage');
if(resultLayer&&resultLayer.parentElement!==document.body)document.body.appendChild(resultLayer);
if(qaidLayer&&qaidLayer.parentElement!==document.body)document.body.appendChild(qaidLayer);
const menuToggle=$('menuToggle'),siteMenu=$('siteMenu');
menuToggle?.addEventListener('click',()=>{const open=siteMenu.classList.toggle('open');menuToggle.setAttribute('aria-expanded',String(open));});
document.addEventListener('click',e=>{if(siteMenu?.classList.contains('open')&&!e.target.closest('.site-header')){siteMenu.classList.remove('open');menuToggle?.setAttribute('aria-expanded','false');}});
loadBuildInfo();
restoreAccount();
document.addEventListener('pointerdown',e=>{if(selectedCardId&&!e.target.closest('#hand [data-card]')){selectedCardId=null;document.querySelectorAll('#hand [data-card].selected').forEach(card=>card.classList.remove('selected'));}});


