const SUITS = ['S','H','D','C'];
const RANKS = ['7','8','9','J','Q','K','10','A'];
const SUN_ORDER = ['7','8','9','J','Q','K','10','A'];
const TRUMP_ORDER = ['7','8','Q','K','10','A','9','J'];
const SUN_POINTS = {A:11,'10':10,K:4,Q:3,J:2,9:0,8:0,7:0};
const TRUMP_POINTS = {J:20,9:14,A:11,'10':10,K:4,Q:3,8:0,7:0};
const teamOf = seat => seat % 2;
// Seats on screen: 0=bottom, 1=left, 2=top, 3=right.
// The dealer's right-hand neighbor is seat-1: 0 -> 3 -> 2 -> 1 -> 0.
// Baloot order on screen: dealer -> right -> opposite -> left.
const { counterClockwiseSeat: nextSeat } = require('./seating');
const { detectPlayerProjects } = require('./projects');
const { scoreRound } = require('./scoring');
const makeDeck = () => SUITS.flatMap(suit => RANKS.map(rank => ({suit,rank,id:suit+rank})));
const shuffle = cards => { const a=[...cards]; for(let i=a.length-1;i>0;i--){const j=require('crypto').randomInt(i+1);[a[i],a[j]]=[a[j],a[i]];} return a; };
const rankValue = (card, trump) => (trump && card.suit===trump ? TRUMP_ORDER : SUN_ORDER).indexOf(card.rank);
const cardPoints = (card, contract) => (contract.type==='hokum' && card.suit===contract.trump ? TRUMP_POINTS[card.rank] : SUN_POINTS[card.rank]);
function winnerOf(trick, contract) {
  const lead=trick[0].card.suit;
  let best=trick[0];
  for(const play of trick.slice(1)){
    const a=play.card,b=best.card;
    const aTrump=contract.type==='hokum'&&a.suit===contract.trump;
    const bTrump=contract.type==='hokum'&&b.suit===contract.trump;
    if(aTrump&&!bTrump) best=play;
    else if(aTrump===bTrump){
      if(aTrump){if(rankValue(a,contract.trump)>rankValue(b,contract.trump))best=play;}
      else if(a.suit===lead&&b.suit!==lead)best=play;
      else if(a.suit===lead&&b.suit===lead&&rankValue(a,null)>rankValue(b,null))best=play;
    }
  }
  return best.seat;
}
function legalCards(hand,trick,contract,seat){
  const trumps=contract.type==='hokum'?hand.filter(c=>c.suit===contract.trump):[];
  const nonTrumps=contract.type==='hokum'?hand.filter(c=>c.suit!==contract.trump):hand;
  if(!trick.length)return contract.restricted&&seat!==contract.lastWinnerSeat&&trumps.length&&nonTrumps.length?nonTrumps:hand;
  const lead=trick[0].card.suit, following=hand.filter(c=>c.suit===lead);
  if(contract.type==='sun')return following.length?following:hand;
  if(following.length)return following;
  if(!trumps.length)return hand;
  const currentWinner=winnerOf(trick,contract);
  const partnerWinning=Number.isInteger(seat)&&teamOf(currentWinner)===teamOf(seat);
  if(partnerWinning)return contract.restricted&&nonTrumps.length?nonTrumps:hand;
  const tableTrumps=trick.filter(p=>p.card.suit===contract.trump);
  if(tableTrumps.length){
    const highest=Math.max(...tableTrumps.map(p=>rankValue(p.card,contract.trump)));
    const over=trumps.filter(c=>rankValue(c,contract.trump)>highest);
    if(over.length)return over;
  }
  return trumps;
}
function createGame(names=['ضيف','ضيف','ضيف','ضيف']){
  return {phase:'dealing',players:names.map((name,seat)=>({name,seat,bot:false})),hands:[[],[],[],[]],scores:[0,0],dealScores:[],dealer:0,turn:0,round:1,upCard:null,contract:null,trick:[],tricks:[],tricksWon:[0,0],history:[],lastWinner:null,auctionPasses:0,auctionOrder:[],postPurchase:null,sunBeforeQueue:[],projectClaims:[[],[],[],[]],projectOptions:[[],[],[],[]],projectDeclared:[false,false,false,false],balotClaims:[]};
}
function deal(game){
  const deck=shuffle(makeDeck());
  game.hands=Array.from({length:4},(_,i)=>deck.slice(i*5,(i+1)*5));
  game.upCard=deck[20]; game.stock=deck.slice(21);
  game.phase='auction1'; game.turn=nextSeat(game.dealer); game.auctionPasses=0; game.auctionOrder=Array.from({length:4},(_,i)=>(game.turn+i)%4); game.postPurchase=null; game.sunBeforeQueue=[];
  game.contract=null;game.trick=[];game.tricks=[];game.tricksWon=[0,0];game.history=[];game.qaidResult=null;game.lastLandTeam=null;game.lastLandPoints=0;
  game.projectClaims=[[],[],[],[]];game.projectOptions=[[],[],[],[]];game.projectDeclared=[false,false,false,false];game.balotClaims=[];
  return game;
}
function auctionOrder(game){
  return game.auctionOrder?.length===4?[...game.auctionOrder]:Array.from({length:4},(_,i)=>(nextSeat(game.dealer)+i)%4);
}
function auctionPosition(game,seat){return auctionOrder(game).indexOf(seat)+1;}
function queueBefore(game,seat){const order=auctionOrder(game),pos=order.indexOf(seat);return order.slice(0,pos).reverse();}
function queueAfter(game,seat){const order=auctionOrder(game),pos=order.indexOf(seat);return [...order.slice(pos+1),...order.slice(0,pos)];}
function ashkalEligible(game,seat){
  const buyerPos=auctionPosition(game,game.postPurchase?.buyer??game.contract?.buyer);
  const pos=auctionPosition(game,seat);
  const allowed=buyerPos===1||buyerPos===2?[3,4]:buyerPos===3?[4]:buyerPos===4?[3]:[];
  return allowed.includes(pos);
}
function beginPostPurchase(game,buyer,stage){
  game.phase='postPurchase';
  game.postPurchase={buyer,stage,responses:{},queue:queueAfter(game,buyer),sunCallerSeat:null,ashkalCallerSeat:null,doubleCallerSeat:null};
  game.turn=game.postPurchase.queue[0]??buyer;
}
function finishPostPurchase(game,buyer,changes={}){
  const prior=game.contract||{};
  const merged={...prior,...changes,buyer};
  const pending={level:merged.level||1,doubleTeam:merged.doubleTeam??null,buyerTeam:merged.buyerTeam,restricted:!!merged.restricted,purchaseType:merged.purchaseType,cardReceiver:merged.cardReceiver,originalBuyerTeam:teamOf(buyer)};
  game.contract=merged;
  game.postPurchase=null;game.sunBeforeQueue=[];
  finishDeal(game);
  game.contract.level=pending.level;game.contract.doubleTeam=pending.doubleTeam;game.contract.restricted=pending.restricted;
  game.contract.purchaseType=pending.purchaseType;
  if(Number.isInteger(pending.cardReceiver))game.contract.cardReceiver=pending.cardReceiver;
  if(Number.isInteger(pending.buyerTeam))game.contract.buyerTeam=pending.buyerTeam;
  game.contract.originalBuyerTeam=pending.originalBuyerTeam;
  return game;
}
function finishSecondHukm(game){
  const buyer=game.postPurchase.buyer;
  game.phase='postPurchase';
  game.contract.buyer=buyer;
  game.postPurchase.stage='doubleResponse';
  game.postPurchase.queue=queueAfter(game,buyer).filter(seat=>teamOf(seat)!==teamOf(buyer));
  game.turn=game.postPurchase.queue[0]??buyer;
  if(!game.postPurchase.queue.length)return finishPostPurchase(game,buyer);
  return game;
}
function beginSunChallenge(game,caller,queue){
  game.phase='postPurchase';
  game.contract={...game.contract,type:'sun',trump:null,buyer:caller,purchaseType:game.contract?.purchaseType||'sun'};
  game.postPurchase.stage='sunChallenge';
  game.postPurchase.sunCallerSeat=caller;
  game.postPurchase.queue=[...queue];
  game.turn=game.postPurchase.queue[0]??caller;
  if(!game.postPurchase.queue.length)return finishPostPurchase(game,caller,{type:'sun',trump:null});
  return game;
}
function chooseContract(game,seat,choice){
  if(!['auction1','auction2'].includes(game.phase)||game.turn!==seat)throw Error('ليس دورك في الشراء');
  const order=auctionOrder(game);
  if(game.phase==='auction1'){
    if(choice==='sun'){
      game.contract={type:'sun',trump:null,buyer:seat};
      beginPostPurchase(game,seat,'sunChallenge');
      game.postPurchase.sunCallerSeat=seat;
      game.postPurchase.queue=queueBefore(game,seat);
      game.turn=game.postPurchase.queue[0]??seat;
      if(!game.postPurchase.queue.length)finishPostPurchase(game,seat,{type:'sun',trump:null});
      return game;
    }
    if(['take','hokum'].includes(choice)){
      game.contract={type:'hokum',trump:game.upCard.suit,buyer:seat,purchaseRound:1};
      beginPostPurchase(game,seat,'responses');
      return game;
    }
    if(choice!=='pass')throw Error('اختيار شراء غير صالح');
    game.auctionPasses++;game.turn=nextSeat(seat);
    if(game.auctionPasses>=4){game.phase='auction2';game.turn=nextSeat(game.dealer);game.auctionPasses=0;}
    return game;
  }
  if(choice==='sun'){
    game.contract={type:'sun',trump:null,buyer:seat,purchaseRound:2};
    beginPostPurchase(game,seat,'sunChallenge');
    game.postPurchase.sunCallerSeat=seat;
    game.postPurchase.queue=queueBefore(game,seat);
    game.turn=game.postPurchase.queue[0]??seat;
    if(!game.postPurchase.queue.length)finishPostPurchase(game,seat,{type:'sun',trump:null});
    return game;
  }
  if(choice==='takeSecond'||choice==='hokum'||choice==='take'||['S','H','D','C'].includes(choice)){
    game.contract={type:'hokum',trump:null,buyer:seat,purchaseRound:2};
    beginPostPurchase(game,seat,'secondPasses');
    return game;
  }
  if(choice!=='pass')throw Error('اختيار شراء غير صالح');
  game.auctionPasses++;game.turn=nextSeat(seat);
  if(game.auctionPasses>=4)game.phase='redeal';
  return game;
}
function availablePostPurchaseOptions(game,seat){
  const p=game.postPurchase;
  if(!p)return [];
  if(game.phase==='postPurchase'){
    if(game.turn!==seat)return [];
    if(p.stage==='responses'){
      const options=['pass','sun'];
      if(ashkalEligible(game,seat))options.push('ashkal');
      return options;
    }
    if(p.stage==='secondPasses')return ['pass'];
    if(p.stage==='sunChallenge'){
      const options=p.queue.length===1?['pass','takeBefore']:['pass','sun'];
      const callerTeam=teamOf(p.sunCallerSeat);
      if(game.scores?.[teamOf(seat)]<100&&game.scores?.[callerTeam]>100)options.push('double');
      return options;
    }
    if(p.stage==='ashkalReplies')return ['pass','sun'];
    if(p.stage==='doubleResponse')return ['pass','double'];
    if(p.stage==='doubleBuyer')return ['pass','three'];
    if(p.stage==='doubleCaller')return ['pass','four'];
    if(p.stage==='doubleBuyerCoffee')return ['pass','coffee'];
  }
  if(game.phase==='buyerConfirm'&&seat===p.buyer)return ['confirmHukm','sun'];
  if(game.phase==='buyerSelectHukm'&&seat===p.buyer)return SUITS.filter(s=>s!==game.upCard.suit).concat('sun');
  return [];
}
function advancePostPurchaseQueue(game){
  const p=game.postPurchase;
  if(p.queue.length){game.turn=p.queue[0];return game;}
  if(p.stage==='responses'){game.phase='buyerConfirm';game.turn=p.buyer;return game;}
  if(p.stage==='secondPasses'){game.phase='buyerSelectHukm';game.turn=p.buyer;return game;}
  if(p.stage==='ashkalReplies')return finishPostPurchase(game,p.ashkalCallerSeat,{type:'sun',trump:null,buyer:p.ashkalCallerSeat});
  if(p.stage==='doubleResponse')return finishPostPurchase(game,p.buyer);
  if(p.stage==='sunChallenge')return finishPostPurchase(game,p.sunCallerSeat,{type:'sun',trump:null});
  if(p.stage==='ashkalReplies')return finishPostPurchase(game,p.ashkalCallerSeat,{type:'sun',trump:null,buyer:p.ashkalCallerSeat});
  return game;
}
function respondPostPurchase(game,seat,choice){
  const options=availablePostPurchaseOptions(game,seat);
  if(!options.includes(choice))throw Error('هذا الخيار غير متاح لك الآن');
  const p=game.postPurchase;
  if(game.phase==='buyerConfirm'){
    if(choice==='confirmHukm')return finishSecondHukm(game);
    return beginSunChallenge(game,p.buyer,queueBefore(game,p.buyer));
  }
  if(game.phase==='buyerSelectHukm'){
    if(choice==='sun')return beginSunChallenge(game,p.buyer,queueBefore(game,p.buyer));
    if(!SUITS.includes(choice)||choice===game.upCard.suit)throw Error('نوع الحكم غير متاح');
    game.contract={...game.contract,type:'hokum',trump:choice,buyer:p.buyer};
    return finishSecondHukm(game);
  }
  if(choice==='pass'){
    if(p.stage==='responses'||p.stage==='secondPasses'){
      p.responses[seat]='pass';p.queue.shift();
      return advancePostPurchaseQueue(game);
    }
    if(p.stage==='ashkalReplies'){p.responses[seat]='pass';p.queue.shift();return advancePostPurchaseQueue(game);}
    if(p.stage==='sunChallenge')return finishPostPurchase(game,p.sunCallerSeat,{type:'sun',trump:null});
    if(p.stage==='doubleResponse'){p.responses[seat]='pass';p.queue.shift();return advancePostPurchaseQueue(game);}
    if(p.stage==='doubleBuyer'){game.contract.level=2;game.contract.restricted=true;return finishPostPurchase(game,p.buyer);}
    if(p.stage==='doubleCaller'){game.contract.level=3;game.contract.restricted=true;return finishPostPurchase(game,p.buyer);}
    if(p.stage==='doubleBuyerCoffee'){game.contract.level=4;game.contract.restricted=true;return finishPostPurchase(game,p.buyer);}
  }
  if(choice==='sun'){
    if(p.stage==='responses')return beginSunChallenge(game,seat,queueBefore(game,seat));
    if(p.stage==='ashkalReplies'){
      const remaining=[...p.queue].slice(1);
      game.contract={...game.contract,type:'sun',trump:null,buyer:seat,purchaseType:'ashkal'};
      return beginSunChallenge(game,seat,remaining);
    }
    if(p.stage==='sunChallenge')return beginSunChallenge(game,seat,[...p.queue].slice(1));
  }
  if(choice==='ashkal'){
    const caller=seat,receiver=(seat+2)%4;
    game.contract={...game.contract,buyer:caller,cardReceiver:receiver,purchaseType:'ashkal',ashkalCallerSeat:caller};
    p.ashkalCallerSeat=caller;p.stage='ashkalReplies';p.queue=queueBefore(game,caller);game.turn=p.queue[0]??caller;
    if(!p.queue.length)return finishPostPurchase(game,caller);
    return game;
  }
  if(choice==='takeBefore')return finishPostPurchase(game,seat,{type:'sun',trump:null,buyer:seat,buyerTeam:teamOf(seat),restricted:false});
  if(choice==='double'&&p.stage==='sunChallenge'){
    game.contract.level=2;game.contract.doubleTeam=teamOf(seat);game.contract.buyerTeam=teamOf(seat);game.contract.restricted=false;
    return finishPostPurchase(game,p.sunCallerSeat,{type:'sun',trump:null,buyerTeam:teamOf(seat),level:2,doubleTeam:teamOf(seat),restricted:false});
  }
  if(choice==='double'&&p.stage==='doubleResponse'){
    game.contract.level=2;game.contract.doubleTeam=teamOf(seat);game.contract.buyerTeam=teamOf(seat);game.contract.doubleCallerSeat=seat;
    p.doubleCallerSeat=seat;p.stage='doubleBuyer';game.turn=p.buyer;return game;
  }
  if(choice==='three'){game.contract.level=3;p.stage='doubleCaller';game.turn=p.doubleCallerSeat;return game;}
  if(choice==='four'){game.contract.level=4;p.stage='doubleBuyerCoffee';game.turn=p.buyer;return game;}
  if(choice==='coffee'){game.contract.level=5;game.contract.restricted=false;return finishPostPurchase(game,p.buyer);}
  throw Error('اختيار غير صالح');
}
function expirePostPurchase(game){
  const p=game.postPurchase;if(!p)return game;
  if(game.phase==='buyerConfirm'){return finishPostPurchase(game,p.buyer);}
  if(game.phase==='buyerSelectHukm'){return game;}
  if(p.stage==='responses'||p.stage==='secondPasses'||p.stage==='doubleResponse'){
    p.queue=[];return advancePostPurchaseQueue(game);
  }
  if(p.stage==='sunChallenge')return finishPostPurchase(game,p.sunCallerSeat,{type:'sun',trump:null});
  if(p.stage==='ashkalReplies')return finishPostPurchase(game,p.ashkalCallerSeat,{type:'sun',trump:null,buyer:p.ashkalCallerSeat});
  if(p.stage==='doubleBuyer'){game.contract.level=2;game.contract.restricted=true;return finishPostPurchase(game,p.buyer);}
  if(p.stage==='doubleCaller'){game.contract.level=3;game.contract.restricted=true;return finishPostPurchase(game,p.buyer);}
  if(p.stage==='doubleBuyerCoffee'){game.contract.level=4;game.contract.restricted=true;return finishPostPurchase(game,p.buyer);}
  return game;
}
function finishDeal(game){
  const buyer=game.contract.buyer;
  game.contract.originalBuyerTeam=teamOf(buyer);game.contract.buyerTeam=teamOf(buyer);game.contract.level=1;game.contract.doubleTeam=null;
  game.hands[Number.isInteger(game.contract.cardReceiver)?game.contract.cardReceiver:buyer].push(game.upCard);
  const remaining=game.stock;
  let k=0;
  for(let n=0;n<4;n++){const seat=(nextSeat(game.dealer)+n)%4;while(game.hands[seat].length<8)game.hands[seat].push(remaining[k++]);}
  const suitOrder=game.contract.type==='hokum'?[game.contract.trump,...SUITS.filter(s=>s!==game.contract.trump)]:SUITS;
  game.hands.forEach(h=>h.sort((a,b)=>suitOrder.indexOf(a.suit)-suitOrder.indexOf(b.suit)||((a.suit===game.contract.trump&&game.contract.type==='hokum'?TRUMP_ORDER:SUN_ORDER).indexOf(a.rank)-(b.suit===game.contract.trump&&game.contract.type==='hokum'?TRUMP_ORDER:SUN_ORDER).indexOf(b.rank))));
  game.initialHands=game.hands.map(h=>h.map(c=>({...c})));game.doubleWindow=true;
  game.projectOptions=game.initialHands.map((hand,seat)=>detectPlayerProjects(hand,game.contract,seat).filter(p=>p.type!=='belot'));
  game.projectClaims=[[],[],[],[]];game.projectDeclared=[false,false,false,false];
  game.projectDeclared=game.projectOptions.map(options=>!options.length);
  if(game.projectDeclared.every(Boolean))game.phase='playing';
  else game.phase='projectDeclaration';
  game.turn=nextSeat(game.dealer);
}
function declareProject(game,seat,projectType){
  if(!['projectDeclaration','playing'].includes(game.phase)||game.history.length>0)throw Error('مرحلة إعلان المشاريع غير متاحة');
  if(!Number.isInteger(seat)||seat<0||seat>3)throw Error('المقعد غير صحيح');
  if(game.projectDeclared[seat])throw Error('تم تسجيل إعلان مشروعك مسبقاً');
  if(projectType!=='pass'){
    const project=(game.projectOptions[seat]||[]).find(p=>p.type===projectType);
    if(!project)throw Error('المشروع غير موجود في أوراقك');
    game.projectClaims[seat]=[project];
  }
  game.projectDeclared[seat]=true;
  if(game.projectDeclared.every(Boolean))game.phase='playing';
  return game;
}
function botProject(game,seat){return game.projectOptions?.[seat]?.[0]?.type||'pass';}
function availableDoubleCalls(game,seat){
  if(game.phase!=='playing'||!game.contract||game.history.length>0||game.doubleWindow===false)return [];
  const c=game.contract,team=teamOf(seat),original=Number.isInteger(c.originalBuyerTeam)?c.originalBuyerTeam:teamOf(c.buyer),level=c.level||1;
  if(c.purchaseRound)return [];
  if(c.type==='sun'){
    return [];
  }
  if(level===1&&team!==original)return ['double'];
  if(level===2&&team===original)return ['three'];
  if(level===3&&team===c.doubleTeam)return ['four'];
  if(level===4&&team===original)return ['coffee'];
  return [];
}
function callDouble(game,seat,call){
  if(!availableDoubleCalls(game,seat).includes(call))throw Error('هذا التصعيد غير متاح لفريقك الآن');
  const c=game.contract,team=teamOf(seat);
  if(call==='double'){c.level=2;c.doubleTeam=team;c.buyerTeam=team;}
  else if(call==='three'){c.level=3;c.buyerTeam=team;}
  else if(call==='four'){c.level=4;c.buyerTeam=team;}
  else if(call==='coffee'){c.level=5;c.buyerTeam=team;}
  c.lastCallerSeat=seat;
  return game;
}
function playCard(game,seat,cardId){
  if(game.phase!=='playing'||game.turn!==seat)throw Error('ليس دورك في اللعب');
  const hand=game.hands[seat],card=hand.find(c=>c.id===cardId);
  if(!card)throw Error('الورقة غير موجودة في يدك');
  const allowed=legalCards(hand,game.trick,game.contract,seat);
  const leadSuit=game.trick[0]?.card?.suit||null;
  const failureToFollow=!!leadSuit&&hand.some(c=>c.suit===leadSuit)&&card.suit!==leadSuit;
  const qaidViolation=!!game.qaidEnabled&&failureToFollow;
  if(!allowed.some(c=>c.id===cardId)&&!qaidViolation)throw Error('هذه الورقة مخالفة لقواعد اللعب');
  if(game.contract.type==='hokum'&&card.suit===game.contract.trump&&['K','Q'].includes(card.rank)){
    const other=card.rank==='K'?'Q':'K';
    const previouslyPlayed=game.history.some(p=>p.seat===seat&&p.card.suit===game.contract.trump&&p.card.rank===other);
    if(previouslyPlayed&&!game.balotClaims.some(p=>p.seat===seat))game.balotClaims.push({type:'belot',strength:0,high:0,suit:game.contract.trump,seat,team:teamOf(seat),label:'بلوت'});
  }
  game.doubleWindow=false;
  game.contract.lastWinnerSeat=game.lastWinner;
  hand.splice(hand.findIndex(c=>c.id===cardId),1);
  game.trick.push({seat,card});game.history.push({seat,card,trickIndex:game.tricks.length,leadSuit,violation:qaidViolation});
  if(game.trick.length<4){game.turn=nextSeat(seat);return game;}
  const winningSeat=winnerOf(game.trick,game.contract);
  const points=game.trick.reduce((sum,p)=>sum+cardPoints(p.card,game.contract),0);
  game.tricksWon[teamOf(winningSeat)]++;
  game.tricks.push({plays:game.trick,winner:winningSeat,team:teamOf(winningSeat),points});
  game.lastWinner=winningSeat;game.contract.lastWinnerSeat=winningSeat;game.trick=[];game.turn=winningSeat;
  if(game.hands.every(h=>h.length===0))finishRound(game);
  return game;
}
function claimQaid(game,claimantSeat,violationIndex,proofIndex){
  if(!game.qaidEnabled||!['playing','roundEnd','gameEnd'].includes(game.phase))throw Error('طلب القيد غير متاح الآن');
  if(!Number.isInteger(claimantSeat)||claimantSeat<0||claimantSeat>3)throw Error('مقعد المطالب غير صحيح');
  const first=game.history[violationIndex];
  if(!first||first.seat===claimantSeat||teamOf(first.seat)===teamOf(claimantSeat))throw Error('لا يمكنك طلب القيد على لاعب من فريقك أو بورقة غير موجودة');
  if(game.qaidResult)throw Error('تم حسم طلب القيد لهذه التوزيعة مسبقاً');
  if(!first.violation)throw Error('الورقة المختارة ليست مخالفة مسجلة');
  const success=true;
  const previous=[...(game.lastAwarded||[0,0])];
  if(['roundEnd','gameEnd'].includes(game.phase))game.scores=game.scores.map((n,i)=>n-(previous[i]||0));
  const winnerTeam=success?teamOf(claimantSeat):teamOf(first.seat);
  const claims=[...(game.projectClaims||[[],[],[],[]]).map(p=>p),game.balotClaims||[]];
  const scored=scoreRound({raw:[0,0],contract:game.contract,projectClaims:claims,dealer:game.dealer,tricksWon:[0,0]});
  const base=game.contract.type==='sun'?26:16;
  const total=base+(scored.scoredProjectPoints||[0,0]).reduce((a,b)=>a+(b||0),0);
  const awarded=[0,0];awarded[winnerTeam]=total;game.scores[winnerTeam]+=total;game.lastAwarded=awarded;
  const buyerTeam=Number.isInteger(game.contract.buyerTeam)?game.contract.buyerTeam:teamOf(game.contract.buyer);
  game.qaidResult={claimantSeat,accusedSeat:first.seat,winnerTeam,success,violationIndex,proofIndex,total,base};
  game.lastScoring={...(game.phase==='roundEnd'?(game.lastScoring||{}):{}),raw:['roundEnd','gameEnd'].includes(game.phase)?(game.lastScoring?.raw||game.lastRaw||[0,0]):[0,0],projects:['roundEnd','gameEnd'].includes(game.phase)?(game.lastScoring?.projects||scored.projects):scored.projects,awarded,buyerSucceeded:winnerTeam===buyerTeam,buyerFailed:winnerTeam!==buyerTeam,roundWinner:winnerTeam,qaid:true,scoredProjectPoints:scored.scoredProjectPoints};
  game.phase=game.scores.some(n=>n>=152)&&game.scores[0]!==game.scores[1]?'gameEnd':'roundEnd';
  if(game.phase==='gameEnd')game.winner=game.scores[0]>game.scores[1]?0:1;
  return game.qaidResult;
}
function finishRound(game){
  const raw=[0,0];
  for(const t of game.tricks)raw[t.team]+=t.points;
  game.lastLandTeam=teamOf(game.lastWinner);game.lastLandPoints=10;
  raw[game.lastLandTeam]+=game.lastLandPoints;
  game.lastRaw=raw;
  const claims=[...game.projectClaims.map(p=>p),game.balotClaims];
  const result=scoreRound({raw,contract:game.contract,projectClaims:claims,dealer:game.dealer,tricksWon:game.tricksWon});
  game.lastScoring=result;
  game.lastAwarded=result.awarded;
  game.phase='roundEnd';
  game.dealScores.push({round:game.round,contract:{...game.contract},raw:[...raw],awarded:[...result.awarded],tricksWon:[...game.tricksWon],buyerFailed:result.buyerFailed,projects:result.projects});
  if(result.coffee){game.scores[result.roundWinner]=Math.max(game.scores[result.roundWinner],152);game.phase='gameEnd';game.winner=result.roundWinner;return;}
  game.scores[0]+=result.awarded[0];game.scores[1]+=result.awarded[1];
  if((game.scores[0]>=152||game.scores[1]>=152)&&game.scores[0]!==game.scores[1]){
    game.phase='gameEnd';game.winner=game.scores[0]>game.scores[1]?0:1;
  }
}
function nextRound(game){
  if(!['roundEnd','redeal'].includes(game.phase))throw Error('لا يمكن بدء توزيعة جديدة الآن');
  game.dealer=nextSeat(game.dealer);game.round++;
  return deal(game);
}
function botBid(game,seat){
  const hand=game.hands[seat],suit=game.upCard.suit,trump=hand.filter(c=>c.suit===suit);
  const strength=trump.reduce((s,c)=>s+({J:6,9:4,A:2,'10':1,K:1,Q:0.5}[c.rank]||0),0);
  if(game.phase==='auction1'){
    const sun=hand.reduce((a,c)=>a+({A:1.4,'10':1.1,K:0.5,Q:0.3}[c.rank]||0),0);
    if(sun>=6)return 'sun';
    return strength>=5?'take':'pass';
  }
  const suits=SUITS.filter(s=>s!==game.upCard.suit);let best={type:'pass',value:0};
  for(const s of suits){const v=hand.filter(c=>c.suit===s).reduce((a,c)=>a+({A:2,'10':1.4,K:0.7,Q:0.4,J:0.5}[c.rank]||0),0);if(v>best.value)best={type:s,value:v};}
  const sun=hand.reduce((a,c)=>a+({A:1.4,'10':1.1,K:0.5,Q:0.3}[c.rank]||0),0);
  if(sun>best.value)best={type:'sun',value:sun};
  return best.value>=5?(game.phase==='auction2'?'takeSecond':best.type):'pass';
}
function botPlay(game,seat){
  const hand=game.hands[seat],legal=legalCards(hand,game.trick,game.contract,seat);
  if(legal.length===1)return legal[0].id;
  const partnerWinning=game.trick.length&&teamOf(winnerOf(game.trick,game.contract))===teamOf(seat);
  const pointsOnTable=game.trick.reduce((s,p)=>s+cardPoints(p.card,game.contract),0);
  const ranked=[...legal].sort((a,b)=>{
    const score=c=>{const trial=[...game.trick,{seat,card:c}],wins=winnerOf(trial,game.contract)===seat;const trump=game.contract.type==='hokum'&&c.suit===game.contract.trump;return (wins?(partnerWinning?-20:pointsOnTable+cardPoints(c,game.contract)+8):0)+(trump?2:0)+rankValue(c,game.contract.trump)*0.1;};
    return score(a)-score(b);
  });
  return ranked[0].id;
}
module.exports={SUITS,RANKS,makeDeck,shuffle,rankValue,cardPoints,winnerOf,legalCards,createGame,deal,chooseContract,playCard,nextRound,botBid,botPlay,declareProject,botProject,availableDoubleCalls,callDouble,availablePostPurchaseOptions,respondPostPurchase,expirePostPurchase,finishRound,claimQaid,teamOf};
