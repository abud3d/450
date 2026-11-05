const { resolveProjects } = require('./projects');
const teamOf = seat => seat % 2;

function convertRaw(raw, type) {
  if (type === 'sun') {
    const remainder = raw % 10;
    const rounded = remainder === 5 ? Math.floor(raw/10)*10+5 : Math.round(raw/10)*10;
    return rounded / 5;
  }
  return Math.floor((raw + 4) / 10);
}

function scoreRound({ raw, contract, projectClaims = [], dealer = 0, tricksWon = [0,0] }) {
  const level=contract.level||1;
  const multiplier=level===2?2:level===3?3:level===4?4:1;
  const projectMultiplier=level===2?2:1;
  const baseTotal = contract.type === 'sun' ? 26 : 16;
  const total=baseTotal*multiplier;
  const buyerTeam=Number.isInteger(contract.buyerTeam)?contract.buyerTeam:teamOf(contract.buyer);
  const originalBuyerTeam=Number.isInteger(contract.originalBuyerTeam)?contract.originalBuyerTeam:teamOf(contract.buyer);
  const counterTeam=1-buyerTeam;
  const projects=resolveProjects(projectClaims,dealer,contract.type);
  const scoredProjectPoints=projects.regularPoints.map((n,i)=>n*projectMultiplier+projects.belotPoints[i]);
  const counterRaw=raw[counterTeam];
  const counterConverted=convertRaw(counterRaw,contract.type);
  const counterProjectPoints=scoredProjectPoints[counterTeam];
  const buyerProjectPoints=scoredProjectPoints[buyerTeam];

  let buyerFailed=false;
  if(level>=2&&contract.doubleTeam!==undefined&&raw[0]===raw[1]){
    buyerFailed=contract.doubleTeam===buyerTeam;
  }else if(contract.type==='sun'){
    const counterProjects=projects.counted.filter(p=>p.team===counterTeam);
    const threshold=counterProjects.some(p=>p.type==='400')?0:counterProjects.length
      ?Math.min(...counterProjects.map(p=>({'20':56,'50':41,'100-run':16,'100-set':16}[p.type]??66)),66)
      :66;
    buyerFailed=counterRaw>threshold||(counterRaw===threshold&&threshold!==65);
  }else{
    const counterProjects=projects.counted.filter(p=>p.team===counterTeam);
    const buyerHasBelot=projects.belots.some(p=>p.team===buyerTeam);
    const threshold=counterProjects.length&&!buyerHasBelot
      ?Math.min(...counterProjects.map(p=>({'20':72,'50':56,'100-run':32,'100-set':32}[p.type]??82)),82)
      :82;
    buyerFailed=counterRaw>threshold||(counterRaw===threshold&&threshold!==81);
  }
  if(level===1&&contract.type==='sun'&&counterRaw===65&&!projects.counted.some(p=>p.team===counterTeam))buyerFailed=false;
  if(level===1&&contract.type==='hokum'&&counterRaw===81&&!projects.counted.some(p=>p.team===counterTeam))buyerFailed=false;

  let roundWinner=buyerFailed?counterTeam:buyerTeam;
  let awarded=[0,0];
  if(level===5){
    // Coffee ends the match after the final hand: compare card points plus projects.
    awarded=[raw[0]+scoredProjectPoints[0],raw[1]+scoredProjectPoints[1]];
    roundWinner=awarded[0]===awarded[1]?(buyerTeam):awarded[0]>awarded[1]?0:1;
    buyerFailed=roundWinner!==buyerTeam;
  }else if(buyerFailed){
    awarded[counterTeam]=total+counterProjectPoints+buyerProjectPoints;
  }else{
    awarded[counterTeam]=counterConverted*multiplier+counterProjectPoints;
    awarded[buyerTeam]=total-counterConverted*multiplier+buyerProjectPoints;
  }

  const capot=tricksWon[0]===8||tricksWon[1]===8;
  if(capot&&level!==5){
    const winningTeam=tricksWon[0]===8?0:1;
    const capotBase=contract.type==='sun'?44:25;
    const capotMultiplier=level>=3?level:1;
    awarded=[0,0];
    awarded[winningTeam]=capotBase*capotMultiplier+scoredProjectPoints[winningTeam];
  }
  return {
    raw:[...raw],awarded,buyerTeam,counterTeam,originalBuyerTeam,buyerFailed,roundWinner,buyerSucceeded:roundWinner===originalBuyerTeam,doubleSucceeded:level>=2&&roundWinner!==originalBuyerTeam,
    convertedCounter:counterConverted,projects,scoredProjectPoints,capot,total,
    multiplier,projectMultiplier,coffee:level===5,
    explanation:level===5?'القهوة: تحسم الصكة بمجموع الأبناط والمشاريع، والأعلى يفوز.':buyerFailed?'المشتري خسر؛ انتقل قيد اللعب للفريق المقابل مع المشاريع المحتسبة.':'المشتري نجح؛ قُسم قيد اللعب بين الفريقين وأضيفت المشاريع.'
  };
}
module.exports={convertRaw,scoreRound};
