XCoord=function(xinit,graph){this.halfWidth=[];for(var i=0;i<graph.GG.vWidth.length;i++){this.halfWidth[i]=Math.floor(graph.GG.vWidth[i]/2)}this.graph=graph;if(xinit){this.xcoord=xinit}else{this.xcoord=this.init_xcoord()}};XCoord.prototype={relationshipOrChhub:function(v){if(this.graph.GG.type[v]==TYPE.RELATIONSHIP||this.graph.GG.type[v]==TYPE.CHILDHUB){return true}return false},getSeparation:function(v1,v2){if(this.relationshipOrChhub(v1)&&this.relationshipOrChhub(v2)){return this.graph.horizontalTwinSeparationDist}if(this.relationshipOrChhub(v1)||this.relationshipOrChhub(v2)){return this.graph.horizontalRelSeparationDist}if((this.graph.GG.type[v1]==TYPE.VIRTUALEDGE||this.graph.GG.type[v2]==TYPE.VIRTUALEDGE)){if(this.graph.GG.hasEdge(v1,v2)||this.graph.GG.hasEdge(v2,v1)){return this.graph.horizontalRelSeparationDist}return this.graph.horizontalTwinSeparationDist}if((this.graph.GG.type[v1]==TYPE.PERSON||this.graph.GG.type[v2]==TYPE.PERSON)&&(this.graph.GG.getTwinGroupId(v1)==this.graph.GG.getTwinGroupId(v2))&&(this.graph.GG.getTwinGroupId(v1)!=null)){return this.graph.horizontalTwinSeparationDist}return this.graph.horizontalPersonSeparationDist},init_xcoord:function(){var xinit=[];for(var r=0;r<this.graph.order.order.length;r++){xinit[this.graph.order.order[r][0]]=this.halfWidth[this.graph.order.order[r][0]];for(var i=1;i<this.graph.order.order[r].length;i++){var vPrev=this.graph.order.order[r][i-1];var v=this.graph.order.order[r][i];var separation=this.getSeparation(vPrev,v);xinit[v]=xinit[vPrev]+this.halfWidth[vPrev]+separation+this.halfWidth[v]}}return xinit},getLeftMostNoDisturbPosition:function(v){var leftNeighbour=this.graph.order.getLeftNeighbour(v,this.graph.ranks[v]);if(leftNeighbour!==null){var leftBoundary=this.getRightEdge(leftNeighbour)+this.getSeparation(v,leftNeighbour)+this.halfWidth[v];return leftBoundary}return -Infinity},getSlackOnTheLeft:function(v){return this.xcoord[v]-this.getLeftMostNoDisturbPosition(v)},getRightMostNoDisturbPosition:function(v,alsoMoveRelationship){var rightNeighbour=this.graph.order.getRightNeighbour(v,this.graph.ranks[v]);if(rightNeighbour!==null){var rightBoundary=this.getLeftEdge(rightNeighbour)-this.getSeparation(v,rightNeighbour)-this.halfWidth[v];if(alsoMoveRelationship&&this.graph.GG.type[rightNeighbour]==TYPE.RELATIONSHIP){var rightMost=this.getRightMostNoDisturbPosition(rightNeighbour);var slack=rightMost-this.xcoord[rightNeighbour];rightBoundary+=slack}return rightBoundary}return Infinity},getSlackOnTheRight:function(v){return this.getRightMostNoDisturbPosition(v,false)-this.xcoord[v]},getLeftEdge:function(v){return this.xcoord[v]-this.halfWidth[v]},getRightEdge:function(v){return this.xcoord[v]+this.halfWidth[v]},shiftLeftOneVertex:function(v,amount){var leftBoundary=this.getLeftMostNoDisturbPosition(v);var actualShift=Math.min(amount,this.xcoord[v]-leftBoundary);this.xcoord[v]-=actualShift;return actualShift},shiftRightOneVertex:function(v,amount){var rightBoundary=this.getRightMostNoDisturbPosition(v);var actualShift=Math.min(amount,rightBoundary-this.xcoord[v]);this.xcoord[v]+=actualShift;return actualShift},shiftRightAndShiftOtherIfNecessary:function(v,amount){this.xcoord[v]+=amount;var rightEdge=this.getRightEdge(v);var rank=this.graph.ranks[v];var order=this.graph.order.vOrder[v];for(var i=order+1;i<this.graph.order.order[rank].length;i++){var rightNeighbour=this.graph.order.order[rank][i];if(this.getLeftEdge(rightNeighbour)>=rightEdge+this.getSeparation(v,rightNeighbour)){break}this.xcoord[rightNeighbour]=rightEdge+this.getSeparation(v,rightNeighbour)+this.halfWidth[rightNeighbour];rightEdge=this.getRightEdge(rightNeighbour);v=rightNeighbour}return amount},moveNodeAsCloseToXAsPossible:function(v,targetX){var x=this.xcoord[v];if(x>targetX){var leftMostOK=this.getLeftMostNoDisturbPosition(v);if(leftMostOK<=targetX){this.xcoord[v]=targetX}else{this.xcoord[v]=leftMostOK}}else{var rightMostOK=this.getRightMostNoDisturbPosition(v);if(rightMostOK>=targetX){this.xcoord[v]=targetX}else{this.xcoord[v]=rightMostOK}}if(this.xcoord[v]!=x){return true}return false},normalize:function(){var leftMostElement=indexOfLastMinElementInArray(this.xcoord);var moveAmount=this.xcoord[leftMostElement]-this.halfWidth[leftMostElement];for(var i=0;i<this.xcoord.length;i++){this.xcoord[i]-=moveAmount}},copy:function(){var newX=new XCoord(this.xcoord.slice(0),this.graph,this.halfWidth);return newX},findVertexSetSlacks:function(set){var leftSlack=Infinity;var rightSlack=Infinity;for(var v in set){if(set.hasOwnProperty(v)){var leftNeighbour=this.graph.order.getLeftNeighbour(v,this.graph.ranks[v]);if(!set.hasOwnProperty(leftNeighbour)){leftSlack=Math.min(leftSlack,this.getSlackOnTheLeft(v))}var rightNeighbour=this.graph.order.getRightNeighbour(v,this.graph.ranks[v]);if(!set.hasOwnProperty(rightNeighbour)){rightSlack=Math.min(rightSlack,this.getSlackOnTheRight(v))}}}return{rightSlack:rightSlack,leftSlack:leftSlack}}};XCoordScore=function(maxRealVertexId){this.score=0;this.inEdgeMaxLen=[];this.maxRealVertexId=maxRealVertexId;this.numStraightLong=0};XCoordScore.prototype={add:function(amount){this.score+=amount},addEdge:function(v,u,length){if(u>this.maxRealVertexId){if(length==0&&v>this.maxRealVertexId){this.numStraightLong++}length/=2}if(!this.inEdgeMaxLen[u]||length>this.inEdgeMaxLen[u]){this.inEdgeMaxLen[u]=length}for(var i=0;i<u;i++){if(this.inEdgeMaxLen[i]===undefined){this.inEdgeMaxLen[i]=0}}},isBettertThan:function(otherScore){if(this.score==otherScore.score){if(this.numStraightLong==otherScore.numStraightLong){var score1=this.inEdgeMaxLen.length==0?0:this.inEdgeMaxLen.reduce(function(a,b){return a+b});var score2=otherScore.inEdgeMaxLen.length==0?0:otherScore.inEdgeMaxLen.reduce(function(a,b){return a+b});if(score1==score2){return(Math.max.apply(null,this.inEdgeMaxLen)<Math.max.apply(null,otherScore.inEdgeMaxLen))}return(score1<score2)}return(this.numStraightLong>otherScore.numStraightLong)}return(this.score<otherScore.score)}};VerticalLevels=function(){this.rankVerticalLevels=[];this.childEdgeLevel=[];this.outEdgeVerticalLevel=[]};VerticalLevels.prototype={copy:function(){var result=new VerticalLevels();result.rankVerticalLevels=this.rankVerticalLevels.slice(0);result.childEdgeLevel=this.childEdgeLevel.slice(0);result.outEdgeVerticalLevel=this.outEdgeVerticalLevel.slice(0);return result}};